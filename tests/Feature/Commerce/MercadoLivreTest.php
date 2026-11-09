<?php

namespace Tests\Feature\Commerce;

use App\Commerce\Catalog\CatalogService;
use App\Commerce\Catalog\Product;
use App\Commerce\Catalog\ProductImage;
use App\Commerce\Catalog\Variant;
use App\Commerce\Channels\ChannelConnection;
use App\Commerce\Channels\InboxItem;
use App\Commerce\Channels\Listing;
use App\Commerce\Channels\MercadoLivre\MercadoLivreConnector;
use App\Commerce\Inventory\StockService;
use App\Commerce\Orders\Order;
use App\Commerce\Stores\OpenStore;
use App\Commerce\Stores\Store;
use App\Models\User;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Files\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Foxy Commerce, fatia 8: conector do Mercado Livre, testado com respostas no formato da API
 * (Http::fake). Nenhum teste chama o Mercado Livre de verdade.
 */
class MercadoLivreTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api.mercadolibre.com';

    private User $owner;

    private Store $store;

    private array $routes = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.mercadolivre' => ['client_id' => 'APP123', 'client_secret' => 'segredo'],
            'commerce.channels.mercadolivre.connector' => MercadoLivreConnector::class,
        ]);
        $this->owner = User::factory()->create();
        $this->store = app(OpenStore::class)->open($this->owner, 'Moda da Ana');
        $this->inStore(function () {
            app(CatalogService::class)->create(['name' => 'Camiseta Básica', 'brand' => 'Ana', 'description' => 'Algodão.', 'price_cents' => 8000, 'sku' => 'CAM'], ['Tamanho'], [['P', 'M']]);
            app(StockService::class)->receive(Variant::where('sku', 'CAM-P')->first(), 5);
            app(StockService::class)->receive(Variant::where('sku', 'CAM-M')->first(), 3);
        });

        $this->routes = [
            self::API.'/oauth/token' => Http::response(['access_token' => 'APP_USR-1', 'refresh_token' => 'TG-1', 'expires_in' => 21600, 'user_id' => 123]),
            self::API.'/users/me' => Http::response(['id' => 123, 'nickname' => 'MODA_ANA']),
            self::API.'/sites/MLB/domain_discovery/search*' => Http::response([['category_id' => 'MLB31447', 'category_name' => 'Camisetas']]),
            self::API.'/items' => Http::response(['id' => 'MLB999', 'variations' => [
                ['id' => 111, 'attributes' => [['id' => 'SELLER_SKU', 'value_name' => 'CAM-P']]],
                ['id' => 222, 'attributes' => [['id' => 'SELLER_SKU', 'value_name' => 'CAM-M']]],
            ]], 201),
            self::API.'/items/MLB999/description' => Http::response(['text' => ''], 201),
            self::API.'/items/MLB999/variations/*' => Http::response(['id' => 222]),
            self::API.'/items/MLB999' => Http::response(['id' => 'MLB999']),
            self::API.'/orders/search*' => Http::response(['results' => [['id' => 2000002]]]),
            self::API.'/orders/2000001' => Http::response($this->mlOrder(2000001, ['seller_sku' => 'CAM-M'])),
            self::API.'/orders/2000002' => Http::response($this->mlOrder(2000002, ['seller_sku' => null])),
        ];
        Http::preventStrayRequests();
    }

    /** Respostas do Mercado Livre neste teste (as trocadas vêm antes: a primeira que casa vale). */
    private function fakeMl(array $overrides = []): void
    {
        Http::fake($overrides + $this->routes);
    }

    private function mlOrder(int $id, array $item): array
    {
        return [
            'id' => $id, 'status' => 'paid', 'date_created' => '2026-11-08T10:15:00.000-03:00', 'total_amount' => 89.9,
            'order_items' => [['item' => ['id' => 'MLB999', 'variation_id' => 222] + $item, 'quantity' => 1, 'unit_price' => 89.9, 'sale_fee' => 14.38]],
            'buyer' => ['id' => 555, 'nickname' => 'CARLA_S', 'first_name' => 'Carla', 'last_name' => 'Souza'],
        ];
    }

    private function url(string $path): string
    {
        return 'http://'.config('foxy.hosts.commerce').$path;
    }

    private function inStore(callable $callback): mixed
    {
        return app(ContextStore::class)->run(ExecutionContext::forUser($this->store->organization, $this->owner->id), $callback);
    }

    private function connect()
    {
        $redirect = (string) $this->actingAs($this->owner)->post($this->url('/app/canais/mercadolivre/conectar'))->headers->get('Location');
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);

        return [$redirect, $this->get($this->url('/app/canais/mercadolivre/retorno?code=TG-CODE&state='.$query['state']))];
    }

    private function publish(): Listing
    {
        $this->connect();
        $product = $this->inStore(fn () => Product::sole());
        $variants = $this->inStore(fn () => Variant::pluck('id')->all());
        $this->post($this->url('/app/anuncios'), [
            'connection_id' => $this->inStore(fn () => ChannelConnection::sole()->id), 'product_id' => $product->id,
            'title' => 'Camiseta Básica Algodão Masculina', 'price' => '89,90', 'variants' => $variants, 'publish' => '1',
            'channel_attributes' => "GENDER=Masculino\nLISTING_TYPE=gold_pro",
        ])->assertSessionHasNoErrors();

        return $this->inStore(fn () => Listing::with('variants.variant')->sole());
    }

    public function test_cada_variacao_vai_com_as_suas_fotos(): void
    {
        $this->fakeMl();
        $this->inStore(function () {
            $product = Product::sole();
            foreach ([[null, null, 'geral'], ['Tamanho', 'P', 'so-p']] as $i => [$name, $value, $file]) {
                $stored = File::forceCreate(['id' => strtolower((string) Str::ulid()), 'organization_id' => $product->organization_id,
                    'owner_type' => 'commerce.product_image', 'disk' => 'public', 'path' => "fotos/{$file}.jpg", 'mime' => 'image/jpeg', 'size' => 1]);
                ProductImage::create(['product_id' => $product->id, 'file_id' => $stored->id, 'position' => $i, 'option_name' => $name, 'option_value' => $value]);
            }
        });

        $this->publish();

        Http::assertSent(function (Request $r) {
            if ($r->url() !== self::API.'/items' || $r->method() !== 'POST') {
                return false;
            }
            $variations = collect($r['variations'])->keyBy(fn ($v) => $v['attributes'][0]['value_name']);

            return count($r['pictures']) === 2
                && count($variations['CAM-P']['picture_ids']) === 2 && str_ends_with($variations['CAM-P']['picture_ids'][0], 'so-p.jpg')
                && count($variations['CAM-M']['picture_ids']) === 1 && str_ends_with($variations['CAM-M']['picture_ids'][0], 'geral.jpg');
        });
    }

    public function test_sem_as_chaves_no_env_o_mercado_livre_fica_em_breve(): void
    {
        $this->fakeMl();
        config(['commerce.channels.mercadolivre.connector' => null]);
        $this->actingAs($this->owner)->get($this->url('/app/canais'))->assertOk()->assertSee('em breve');
        $this->post($this->url('/app/canais/mercadolivre/conectar'))->assertNotFound();
    }

    public function test_conectar_pela_autorizacao_do_mercado_livre(): void
    {
        $this->fakeMl();
        [$redirect, $callback] = $this->connect();

        $this->assertStringStartsWith('https://auth.mercadolivre.com.br/authorization?', $redirect);
        $this->assertStringContainsString('client_id=APP123', $redirect);
        $this->assertStringContainsString(urlencode($this->url('/app/canais/mercadolivre/retorno')), $redirect);
        $callback->assertRedirect($this->url('/app/canais'))->assertSessionHas('status');

        $connection = $this->inStore(fn () => ChannelConnection::sole());
        $this->assertSame('123', $connection->external_account_id);
        $this->assertSame('MODA_ANA', $connection->name);
        $this->assertSame('TG-1', $connection->refresh_token);
        Http::assertSent(fn (Request $r) => $r->url() === self::API.'/oauth/token' && $r['grant_type'] === 'authorization_code' && $r['client_secret'] === 'segredo' && $r['code'] === 'TG-CODE');
    }

    public function test_publicar_com_variacoes_sku_e_categoria_sugerida(): void
    {
        $this->fakeMl();
        $listing = $this->publish();

        $this->assertSame('active', $listing->status);
        $this->assertSame('MLB999', $listing->external_id);
        $this->assertSame(['CAM-M' => '222', 'CAM-P' => '111'], $listing->variants->mapWithKeys(fn ($lv) => [$lv->variant->sku => $lv->external_variation_id])->sortKeys()->all());

        Http::assertSent(function (Request $r) {
            if ($r->url() !== self::API.'/items' || $r->method() !== 'POST') {
                return false;
            }
            $variations = collect($r['variations'])->keyBy(fn ($v) => $v['attributes'][0]['value_name']);

            return $r['category_id'] === 'MLB31447' && $r['price'] == 89.9 && $r['currency_id'] === 'BRL' && $r['listing_type_id'] === 'gold_pro'
                && collect($r['attributes'])->contains(fn ($a) => $a['id'] === 'GENDER' && $a['value_name'] === 'Masculino')
                && collect($r['attributes'])->contains(fn ($a) => $a['id'] === 'BRAND' && $a['value_name'] === 'Ana')
                && $variations['CAM-P']['available_quantity'] === 5 && $variations['CAM-M']['available_quantity'] === 3
                && $variations['CAM-M']['attribute_combinations'] === [['id' => 'SIZE', 'value_name' => 'M']];
        });
        Http::assertSent(fn (Request $r) => $r->url() === self::API.'/items/MLB999/description' && $r['plain_text'] === 'Algodão.');
    }

    public function test_erro_do_mercado_livre_vai_para_o_anuncio(): void
    {
        $this->fakeMl([self::API.'/items' => Http::response(['message' => 'Validation error', 'error' => 'validation_error', 'status' => 400,
            'cause' => [['code' => 'item.attribute.missing_required', 'message' => 'The attributes [SIZE_GRID_ID] are required for category MLB31447.']]], 400)]);

        $listing = $this->publish();
        $this->assertSame('error', $listing->status);
        $this->assertStringContainsString('SIZE_GRID_ID', $listing->last_error);
        $this->assertStringStartsWith('Mercado Livre:', $listing->last_error);
    }

    public function test_estoque_vai_por_variacao(): void
    {
        $this->fakeMl();
        $this->publish();
        $this->post($this->url('/app/estoque/entrada'), ['items' => [['sku' => 'CAM-M', 'quantity' => 4]]])->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === self::API.'/items/MLB999/variations/222' && $r['available_quantity'] === 7);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === self::API.'/items/MLB999/variations/111');
    }

    public function test_aviso_de_pedido_do_mercado_livre_vira_pedido_com_taxa(): void
    {
        $this->fakeMl();
        $this->publish();
        $this->postJson($this->url('/webhooks/commerce/mercadolivre'), ['resource' => '/orders/2000001', 'user_id' => 123, 'topic' => 'orders_v2', 'application_id' => 1])->assertOk();
        // Outros tópicos (ex.: perguntas) são ignorados.
        $this->postJson($this->url('/webhooks/commerce/mercadolivre'), ['resource' => '/questions/1', 'user_id' => 123, 'topic' => 'questions'])->assertOk();

        $order = $this->inStore(fn () => Order::with('customer', 'items')->sole());
        $this->assertSame('2000001', $order->external_id);
        $this->assertSame(8990, $order->total_cents);
        $this->assertSame(1438, $order->fee_cents);
        $this->assertSame('Carla Souza', $order->customer->name);
        $this->assertSame('CAM-M', $order->items[0]->sku);
        $this->assertSame(1, InboxItem::count());
    }

    public function test_conferencia_acha_o_pedido_e_o_sku_pela_variacao_quando_o_canal_nao_manda(): void
    {
        $this->fakeMl();
        $this->publish();
        $this->artisan('commerce:canais')->assertSuccessful();

        $order = $this->inStore(fn () => Order::with('items')->sole());
        $this->assertSame('2000002', $order->external_id);
        $this->assertSame('CAM-M', $order->items[0]->sku);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), self::API.'/orders/search') && $r['seller'] === '123');
    }

    public function test_token_vencido_renova_com_o_refresh_token_novo(): void
    {
        $this->fakeMl([self::API.'/oauth/token' => Http::sequence()
            ->push(['access_token' => 'APP_USR-1', 'refresh_token' => 'TG-1', 'expires_in' => 21600, 'user_id' => 123])
            ->push(['access_token' => 'APP_USR-2', 'refresh_token' => 'TG-2', 'expires_in' => 21600, 'user_id' => 123])]);
        $this->connect();
        $this->inStore(fn () => ChannelConnection::sole()->update(['token_expires_at' => now()->subMinute()]));

        $this->artisan('commerce:canais')->assertSuccessful();

        $connection = $this->inStore(fn () => ChannelConnection::sole());
        $this->assertSame('APP_USR-2', $connection->access_token);
        $this->assertSame('TG-2', $connection->refresh_token);
        Http::assertSent(fn (Request $r) => $r->url() === self::API.'/oauth/token' && $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'TG-1');
    }
}
