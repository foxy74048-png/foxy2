<?php

namespace App\Commerce\Channels\MercadoLivre;

use App\Commerce\Catalog\ProductImage;
use App\Commerce\Channels\ChannelConnection;
use App\Commerce\Channels\ChannelConnector;
use App\Commerce\Channels\ChannelError;
use App\Commerce\Channels\ExternalOrder;
use App\Commerce\Channels\Listing;
use App\Commerce\Channels\ListingVariant;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Mercado Livre Brasil (site MLB) pela API pública api.mercadolibre.com (docs/09, fatia 8).
 * Pontos a conferir na documentação oficial ao criar o app: modelo "User Products" (preço por variação) em
 * algumas categorias, tabela de medidas obrigatória em moda (SIZE_GRID_ID) e nomes de atributos por categoria.
 * Erros do Mercado Livre vão para a tela do anúncio como vieram (ChannelError).
 */
class MercadoLivreConnector implements ChannelConnector
{
    private const API = 'https://api.mercadolibre.com';

    private const AUTH = 'https://auth.mercadolivre.com.br/authorization';

    /** Características da grade do Foxy → atributo de variação do Mercado Livre. */
    private const VARIATION_ATTRIBUTES = ['tamanho' => 'SIZE', 'cor' => 'COLOR', 'voltagem' => 'VOLTAGE', 'sabor' => 'FLAVOR'];

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return self::AUTH.'?'.http_build_query([
            'response_type' => 'code', 'client_id' => config('services.mercadolivre.client_id'), 'redirect_uri' => $redirectUri, 'state' => $state,
        ]);
    }

    public function connect(string $code, string $redirectUri): array
    {
        $token = $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri]);
        $me = $this->check(Http::withToken($token['access_token'])->acceptJson()->timeout(20)->get(self::API.'/users/me'))->json();

        return [
            'external_account_id' => (string) ($token['user_id'] ?? $me['id']),
            'name' => $me['nickname'] ?? null,
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? null,
            'expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 21600)),
        ];
    }

    public function refresh(ChannelConnection $connection): void
    {
        if (! $connection->refresh_token) {
            throw new ChannelError('A autorização do Mercado Livre venceu. Conecte a conta de novo em Marketplaces.');
        }

        // O refresh token do Mercado Livre é de uso único: guardar sempre o novo.
        $token = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $connection->refresh_token]);
        $connection->update([
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? $connection->refresh_token,
            'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 21600)),
        ]);
    }

    public function publish(ChannelConnection $connection, Listing $listing, array $variants): array
    {
        $listing->loadMissing('product');
        $product = $listing->product;
        $extra = $this->listingAttributes($listing);
        $category = $listing->external_category ?: $this->predictCategory($connection, $listing->title);
        $pictures = ProductImage::with('file')->where('product_id', $product->id)->orderBy('position')->get()
            ->map(fn ($image) => ['source' => $image->file->url()])->values()->all();

        $attributes = collect([
            'BRAND' => $product->brand,
            'MODEL' => $product->name,
        ])->merge($extra['attributes'])->filter(fn ($v) => filled($v))
            ->map(fn ($value, $id) => ['id' => $id, 'value_name' => (string) $value])->values();

        $body = [
            'title' => $listing->title,
            'category_id' => $category,
            'price' => $listing->price_cents / 100,
            'currency_id' => 'BRL',
            'buying_mode' => 'buy_it_now',
            'condition' => 'new',
            'listing_type_id' => $extra['listing_type'],
            'pictures' => $pictures,
        ];

        $withGrid = collect($variants)->contains(fn ($v) => ! empty($v['options']));
        if ($withGrid) {
            $body['attributes'] = $attributes->all();
            $body['variations'] = collect($variants)->map(fn ($v) => [
                'attribute_combinations' => $this->combinations($v['options'] ?? []),
                'price' => $listing->price_cents / 100,
                'available_quantity' => $v['quantity'],
                'attributes' => [['id' => 'SELLER_SKU', 'value_name' => $v['sku']]],
                'picture_ids' => array_column($pictures, 'source'),
            ])->values()->all();
        } else {
            $only = collect($variants)->first();
            $body['available_quantity'] = $only['quantity'];
            $body['attributes'] = $attributes->push(['id' => 'SELLER_SKU', 'value_name' => $only['sku']])->all();
        }

        $item = $this->api($connection)->post('/items', $body);
        $item = $this->check($item)->json();

        if (filled($product->description)) {
            // Descrição vai separada; se falhar, o anúncio já existe e o lojista ajusta no canal.
            rescue(fn () => $this->check($this->api($connection)->post("/items/{$item['id']}/description", ['plain_text' => Str::limit(strip_tags($product->description), 49000, '')])), report: false);
        }

        $variations = [];
        if ($withGrid) {
            $bySku = collect($item['variations'] ?? [])->mapWithKeys(fn ($v) => [$this->variationSku($v) => (string) $v['id']]);
            foreach ($variants as $variantId => $v) {
                $variations[$variantId] = $bySku[$v['sku']] ?? null;
            }
        }

        return ['external_id' => (string) $item['id'], 'variations' => array_filter($variations)];
    }

    public function update(ChannelConnection $connection, Listing $listing): void
    {
        $listing->loadMissing('variants');
        $variationIds = $listing->variants->pluck('external_variation_id')->filter();

        if ($variationIds->isEmpty()) {
            $this->check($this->api($connection)->put("/items/{$listing->external_id}", ['title' => $listing->title, 'price' => $listing->price_cents / 100]));

            return;
        }

        // Com variações, o preço é de cada variação. O título só muda enquanto o anúncio não tem vendas.
        $this->check($this->api($connection)->put("/items/{$listing->external_id}", ['title' => $listing->title]));
        foreach ($variationIds as $id) {
            $this->check($this->api($connection)->put("/items/{$listing->external_id}/variations/{$id}", ['price' => $listing->price_cents / 100]));
        }
    }

    public function pause(ChannelConnection $connection, Listing $listing, bool $paused): void
    {
        $this->check($this->api($connection)->put("/items/{$listing->external_id}", ['status' => $paused ? 'paused' : 'active']));
    }

    public function pushStock(ChannelConnection $connection, Listing $listing, array $quantities): void
    {
        foreach ($quantities as $externalId => $quantity) {
            // Uma variação por vez: mandar a lista de variações pela metade apagaria as outras no Mercado Livre.
            $path = $externalId === $listing->external_id ? "/items/{$listing->external_id}" : "/items/{$listing->external_id}/variations/{$externalId}";
            $this->check($this->api($connection)->put($path, ['available_quantity' => $quantity]));
        }
    }

    public function parseNotification(Request $request): ?array
    {
        $data = $request->json()->all();
        if (! isset($data['topic'], $data['resource']) || ! in_array($data['topic'], ['orders_v2', 'orders'], true)) {
            return null;
        }

        return ['external_account_id' => isset($data['user_id']) ? (string) $data['user_id'] : null, 'topic' => (string) $data['topic'], 'resource' => (string) $data['resource']];
    }

    public function fetchOrder(ChannelConnection $connection, string $resource): ExternalOrder
    {
        $id = Str::afterLast(trim($resource, '/'), '/');
        $order = $this->check($this->api($connection)->get("/orders/{$id}"))->json();

        $items = [];
        $fee = 0;
        foreach ($order['order_items'] ?? [] as $line) {
            $quantity = (int) $line['quantity'];
            $items[] = ['sku' => $this->orderSku($connection, $line['item'] ?? []), 'quantity' => $quantity, 'unit_price_cents' => (int) round($line['unit_price'] * 100)];
            $fee += (int) round(($line['sale_fee'] ?? 0) * 100) * $quantity;
        }

        $buyer = $order['buyer'] ?? [];
        $name = trim(($buyer['first_name'] ?? '').' '.($buyer['last_name'] ?? '')) ?: ($buyer['nickname'] ?? null);

        return new ExternalOrder(
            externalId: (string) $order['id'],
            status: match ($order['status'] ?? '') {
                'paid' => 'paid',
                'cancelled', 'invalid' => 'canceled',
                default => 'pending',
            },
            items: $items,
            placedAt: Carbon::parse($order['date_created'] ?? now())->setTimezone(config('app.timezone')),
            feeCents: $fee,
            buyerName: $name,
        );
    }

    public function recentOrders(ChannelConnection $connection, Carbon $since): array
    {
        $response = $this->check($this->api($connection)->get('/orders/search', [
            'seller' => $connection->external_account_id,
            'order.date_last_updated.from' => $since->copy()->utc()->format('Y-m-d\TH:i:s.000\Z'),
            'sort' => 'date_desc',
            'limit' => 50,
        ]))->json();

        return collect($response['results'] ?? [])->map(fn ($o) => '/orders/'.$o['id'])->all();
    }

    /** Sugere a categoria pelo título (preditor do Mercado Livre). */
    private function predictCategory(ChannelConnection $connection, string $title): string
    {
        $found = $this->check($this->api($connection)->get('/sites/MLB/domain_discovery/search', ['q' => $title, 'limit' => 1]))->json();

        return $found[0]['category_id'] ?? throw new ChannelError('O Mercado Livre não sugeriu categoria para este título. Informe o código da categoria (ex.: MLB31447) no anúncio.');
    }

    /** "Tamanho: M, Cor: Azul" → combinações de atributos de variação. */
    private function combinations(array $options): array
    {
        return collect($options)->map(function ($value, $name) {
            $id = self::VARIATION_ATTRIBUTES[Str::lower(Str::ascii($name))] ?? null;
            if (! $id) {
                throw new ChannelError("O Mercado Livre não reconhece a característica \"{$name}\" como variação. Use Tamanho, Cor, Voltagem ou Sabor na grade.");
            }

            return ['id' => $id, 'value_name' => (string) $value];
        })->values()->all();
    }

    /**
     * Atributos digitados no anúncio ("GENDER=Feminino", "SIZE_GRID_ID=123", "LISTING_TYPE=gold_pro").
     *
     * @return array{attributes: array<string, string>, listing_type: string}
     */
    private function listingAttributes(Listing $listing): array
    {
        $attributes = collect($listing->getAttribute('attributes') ?? [])->mapWithKeys(fn ($v, $k) => [Str::upper(trim($k)) => trim((string) $v)]);
        $type = $attributes->pull('LISTING_TYPE') ?: 'gold_special';

        return ['attributes' => $attributes->all(), 'listing_type' => $type];
    }

    private function variationSku(array $variation): string
    {
        foreach ($variation['attributes'] ?? [] as $attribute) {
            if (($attribute['id'] ?? null) === 'SELLER_SKU') {
                return (string) $attribute['value_name'];
            }
        }

        return (string) ($variation['seller_custom_field'] ?? '');
    }

    /** SKU do item do pedido: o "seller_sku" que o Foxy mandou; se faltar, pela variação ligada ao anúncio. */
    private function orderSku(ChannelConnection $connection, array $item): string
    {
        $sku = $item['seller_sku'] ?? $item['seller_custom_field'] ?? null;
        if (filled($sku)) {
            return (string) $sku;
        }

        $linked = ListingVariant::with('variant')
            ->whereHas('listing', fn ($q) => $q->where('connection_id', $connection->id)->where('external_id', (string) ($item['id'] ?? '')))
            ->when(filled($item['variation_id'] ?? null), fn ($q) => $q->where('external_variation_id', (string) $item['variation_id']))
            ->first();

        return $linked?->variant?->sku ?? (string) ($item['id'] ?? 'desconhecido');
    }

    /** Cliente da API com o token da conta; renova e tenta de novo uma vez quando o token venceu. */
    private function api(ChannelConnection $connection): PendingRequest
    {
        if ($connection->token_expires_at?->lt(now()->addMinutes(2))) {
            $this->refresh($connection);
        }

        return Http::baseUrl(self::API)->acceptJson()->asJson()->timeout(30)->withToken($connection->access_token)
            ->retry(2, 500, function ($exception, PendingRequest $request) use ($connection) {
                if ($exception instanceof ConnectionException) {
                    return true;
                }
                if (($exception->response?->status() ?? 0) === 401) {
                    $this->refresh($connection);
                    $request->withToken($connection->access_token);

                    return true;
                }

                return ($exception->response?->status() ?? 0) >= 500 || ($exception->response?->status() ?? 0) === 429;
            }, throw: false);
    }

    private function token(array $params): array
    {
        $response = Http::asForm()->acceptJson()->timeout(20)->post(self::API.'/oauth/token', $params + [
            'client_id' => config('services.mercadolivre.client_id'),
            'client_secret' => config('services.mercadolivre.client_secret'),
        ]);

        return $this->check($response)->json();
    }

    /** Resposta de erro do Mercado Livre → ChannelError com a explicação deles. */
    private function check(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }

        $json = $response->json() ?? [];
        $causes = collect($json['cause'] ?? [])->map(fn ($c) => is_array($c) ? ($c['message'] ?? null) : $c)->filter()->implode(' · ');
        $message = $causes ?: ($json['message'] ?? $json['error'] ?? 'erro '.$response->status());

        throw new ChannelError('Mercado Livre: '.Str::limit($message, 1500));
    }
}
