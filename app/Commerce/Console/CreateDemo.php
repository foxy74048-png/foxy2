<?php

namespace App\Commerce\Console;

use App\Commerce\Accounts\AccountSync;
use App\Commerce\Catalog\CatalogService;
use App\Commerce\Catalog\Category;
use App\Commerce\Catalog\Variant;
use App\Commerce\Fiscal\FiscalDocument;
use App\Commerce\Fiscal\NfeXml;
use App\Commerce\Inventory\StockLevel;
use App\Commerce\Inventory\StockService;
use App\Commerce\Orders\Customer;
use App\Commerce\Orders\Order;
use App\Commerce\Orders\OrderService;
use App\Commerce\Stores\OpenStore;
use App\Commerce\Stores\Store;
use App\Models\User;
use App\Platform\Billing\Subscription;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Memberships\Membership;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Loja de demonstração com meses de vendas em todos os canais, para ver o painel e cada tela com dados.
 * Cada pedido é criado "no dia dele" (relógio simulado), então histórico, estoque e auditoria ficam coerentes.
 */
class CreateDemo extends Command
{
    protected $signature = 'commerce:demo {--email=demo@foxy.net.br} {--senha=} {--dias=180} {--fresh : apaga a loja demo e cria de novo}';

    protected $description = 'Cria (ou recria) a loja demo do Foxy Commerce com catálogo, estoque, clientes e pedidos';

    public const STORE_NAME = 'Moda Demo Foxy';

    private const CHANNELS = ['mercadolivre' => 34, 'shopee' => 24, 'magalu' => 9, 'shein' => 9, 'whatsapp' => 12, 'balcao' => 8, 'instagram' => 4];

    private const FEES = ['mercadolivre' => 0.16, 'shopee' => 0.20, 'magalu' => 0.16, 'shein' => 0.16];

    public function handle(ContextStore $contexts, OpenStore $stores, CatalogService $catalog, StockService $stock, OrderService $orders): int
    {
        mt_srand(2026);
        $email = Str::lower($this->option('email'));
        $user = User::where('email', $email)->first();
        $existing = $user ? $this->demoOrganizationId($user) : null;

        if ($existing && ! $this->option('fresh')) {
            $this->warn('A loja demo já existe. Use --fresh para apagar e criar de novo.');

            return self::FAILURE;
        }
        if ($existing) {
            $this->purge($existing);
        }

        $password = $this->option('senha') ?: Str::password(14, symbols: false);
        $user ??= User::create(['name' => 'Conta Demo', 'email' => $email, 'password' => $password]);
        $user->forceFill(['password' => $password, 'email_verified_at' => now()])->save();

        $days = max(7, (int) $this->option('dias'));
        $realNow = now();
        $start = now()->subDays($days)->setTime(9, 0);

        Carbon::setTestNow($start);
        try {
            $store = $stores->open($user, self::STORE_NAME, '11999990000');
            Subscription::where('organization_id', $store->organization_id)->update(['founder_at' => now()]);

            $contexts->run(ExecutionContext::forUser($store->organization, $user->id, 'demo'), function () use ($catalog, $stock, $orders, $start, $days, $realNow) {
                $variants = $this->catalog($catalog);
                $this->stockUp($stock, $variants, 18);
                $customers = $this->customers();
                $count = $this->orders($orders, $stock, $variants, $customers, $start, $days, $realNow);
                $this->info("Pedidos criados: {$count}");
            });
        } finally {
            Carbon::setTestNow();
        }

        AccountSync::sync($store->organization->fresh());

        $this->info('Loja demo pronta: https://'.config('foxy.hosts.commerce').'/app');
        $this->line("E-mail: {$email}");
        $this->line("Senha: {$password}");

        return self::SUCCESS;
    }

    /** @return array<string, Variant> SKU => variante */
    private function catalog(CatalogService $catalog): array
    {
        $roupas = Category::create(['name' => 'Roupas']);
        $acess = Category::create(['name' => 'Acessórios']);
        $cat = [
            'camisetas' => Category::create(['name' => 'Camisetas', 'parent_id' => $roupas->id])->id,
            'calcas' => Category::create(['name' => 'Calças', 'parent_id' => $roupas->id])->id,
            'vestidos' => Category::create(['name' => 'Vestidos', 'parent_id' => $roupas->id])->id,
            'bones' => Category::create(['name' => 'Bonés', 'parent_id' => $acess->id])->id,
            'bolsas' => Category::create(['name' => 'Bolsas', 'parent_id' => $acess->id])->id,
        ];

        $specs = ['weight_g' => 250, 'height_cm' => 3, 'width_cm' => 25, 'length_cm' => 30, 'ncm' => '61091000'];
        foreach ([
            ['Camiseta Básica Algodão', 'CAM-BAS', 'camisetas', 5990, 2200, ['Tamanho' => ['P', 'M', 'G', 'GG'], 'Cor' => ['Branco', 'Preto', 'Azul']]],
            ['Camiseta Estampada Raposa', 'CAM-EST', 'camisetas', 7990, 2800, ['Tamanho' => ['P', 'M', 'G'], 'Cor' => ['Preto', 'Off-white']]],
            ['Calça Jeans Slim', 'CAL-JEA', 'calcas', 15990, 6800, ['Tamanho' => ['36', '38', '40', '42', '44']]],
            ['Calça Jogger Moletom', 'CAL-JOG', 'calcas', 11990, 4500, ['Tamanho' => ['P', 'M', 'G'], 'Cor' => ['Cinza', 'Preto']]],
            ['Vestido Midi Floral', 'VES-MID', 'vestidos', 18990, 7200, ['Tamanho' => ['P', 'M', 'G']]],
            ['Vestido Tubinho', 'VES-TUB', 'vestidos', 14990, 5500, ['Tamanho' => ['P', 'M', 'G'], 'Cor' => ['Preto', 'Vinho']]],
            ['Boné Aba Curva', 'BON-CUR', 'bones', 6990, 2100, ['Cor' => ['Preto', 'Bege', 'Azul']]],
            ['Bolsa Transversal Couro', 'BOL-TRA', 'bolsas', 22990, 9500, ['Cor' => ['Caramelo', 'Preto']]],
            // Encalhado de propósito: nunca vende (aparece como "parado" nos relatórios).
            ['Cinto de Couro Trançado', 'CIN-TRA', 'bolsas', 8990, 3500, []],
        ] as [$name, $sku, $category, $price, $cost, $grid]) {
            $catalog->create(
                ['name' => $name, 'sku' => $sku, 'category_id' => $cat[$category], 'brand' => 'Foxy Demo', 'price_cents' => $price, 'cost_cents' => $cost, 'specs' => $specs,
                    'description' => "{$name} da coleção demonstração. Tecido confortável, acabamento caprichado."],
                array_keys($grid), array_values($grid),
            );
        }

        $id = fn (string $sku) => Variant::where('sku', $sku)->value('id');
        $catalog->createKit(['name' => 'Kit 2 Camisetas Básicas', 'sku' => 'KIT-2CAM', 'category_id' => $cat['camisetas'], 'price_cents' => 10990],
            [$id('CAM-BAS-M-BRANCO') => 1, $id('CAM-BAS-M-PRETO') => 1]);
        $catalog->createKit(['name' => 'Kit Look Verão', 'sku' => 'KIT-VERAO', 'category_id' => $cat['camisetas'], 'price_cents' => 13990],
            [$id('CAM-EST-M-PRETO') => 1, $id('BON-CUR-PRETO') => 1]);

        return Variant::with('product')->get()->keyBy('sku')->all();
    }

    private function stockUp(StockService $stock, array $variants, int $quantity): void
    {
        foreach ($variants as $variant) {
            if (! $variant->is_kit) {
                $stock->receive($variant, $quantity + mt_rand(0, 15), 'Estoque inicial (demo)');
                $stock->setMinimum($variant, 8);
            }
        }
    }

    private function restock(StockService $stock, array $variants): void
    {
        $sellable = collect($variants)->reject(fn (Variant $v) => $v->is_kit)->values();
        $available = $stock->availability($sellable);
        foreach ($sellable as $variant) {
            // Algumas cores e tamanhos encalham de propósito (aparecem como "parado" ou "lento" nos relatórios).
            if ($available[$variant->id] < 8 && ! str_ends_with($variant->sku, '-GG-AZUL')) {
                $stock->receive($variant, 25 - max(0, $available[$variant->id]), 'Reposição do fornecedor (demo)');
            }
        }
    }

    private function customers(): array
    {
        $first = ['Ana', 'Beatriz', 'Carla', 'Daniela', 'Eduarda', 'Fernanda', 'Gabriela', 'Helena', 'Isabela', 'Juliana', 'Larissa', 'Mariana', 'Natália', 'Paula', 'Renata', 'Sofia', 'Bruno', 'Carlos', 'Diego', 'Felipe', 'Gustavo', 'Lucas', 'Marcos', 'Rafael'];
        $last = ['Silva', 'Souza', 'Oliveira', 'Santos', 'Lima', 'Pereira', 'Costa', 'Rodrigues', 'Almeida', 'Nascimento', 'Carvalho', 'Ribeiro'];
        $cities = [['São Paulo', 'SP'], ['Campinas', 'SP'], ['Rio de Janeiro', 'RJ'], ['Belo Horizonte', 'MG'], ['Curitiba', 'PR'], ['Porto Alegre', 'RS'], ['Salvador', 'BA'], ['Recife', 'PE'], ['Goiânia', 'GO'], ['Florianópolis', 'SC']];

        $customers = [];
        for ($i = 0; $i < 80; $i++) {
            $name = $first[array_rand($first)].' '.$last[array_rand($last)];
            [$city, $state] = $cities[array_rand($cities)];
            $customers[] = Customer::create([
                'name' => $name, 'phone' => '119'.mt_rand(10000000, 99999999), 'city' => $city, 'state' => $state,
                'email' => Str::slug($name, '.').$i.'@exemplo.com.br',
            ]);
        }

        return $customers;
    }

    private function orders(OrderService $orders, StockService $stock, array $variants, array $customers, Carbon $start, int $days, Carbon $realNow): int
    {
        $sellable = array_values(array_filter($variants, fn (Variant $v) => ! $v->is_kit && $v->sku !== 'CIN-TRA'));
        $kits = array_values(array_filter($variants, fn (Variant $v) => $v->is_kit));
        $count = 0;

        for ($d = 0; $d <= $days; $d++) {
            $day = $start->copy()->addDays($d);
            // Reposição semanal, como numa loja de verdade: só o que está acabando volta para ~25 unidades.
            if ($d > 0 && $d % 7 === 0) {
                Carbon::setTestNow($day->copy()->setTime(8, 0));
                $this->restock($stock, $variants);
            }

            // Cresce com o tempo; fim de semana vende um pouco mais.
            $base = 2 + (int) round(4 * $d / max(1, $days));
            $perDay = max(0, $base + mt_rand(-1, 2) + ($day->isWeekend() ? 1 : 0));
            $age = $days - $d;

            for ($n = 0; $n < $perDay; $n++) {
                $placedAt = $day->copy()->setTime(mt_rand(8, 22), mt_rand(0, 59));
                if ($placedAt->gt($realNow)) {
                    continue;
                }
                Carbon::setTestNow($placedAt);

                $channel = $this->pick(self::CHANNELS);
                $marketplace = isset(self::FEES[$channel]);
                $items = [];
                foreach (range(1, $this->pick([1 => 70, 2 => 22, 3 => 8])) as $_) {
                    $variant = (mt_rand(1, 100) <= 8) ? $kits[array_rand($kits)] : $sellable[array_rand($sellable)];
                    $items[] = ['variant' => $variant, 'quantity' => mt_rand(1, 100) <= 85 ? 1 : 2];
                }
                $subtotal = array_sum(array_map(fn ($i) => $i['variant']->price_cents * $i['quantity'], $items));
                $awaiting = ! $marketplace && $age <= 1 && mt_rand(1, 100) <= 35;

                $order = $orders->place([
                    'channel' => $channel,
                    'external_id' => $marketplace ? strtoupper(substr($channel, 0, 3)).'-'.mt_rand(100000000, 999999999) : null,
                    'customer_id' => (! $marketplace || mt_rand(1, 100) <= 45) ? $customers[array_rand($customers)]->id : null,
                    'items' => $items,
                    'paid' => ! $awaiting,
                    'payment_method' => $marketplace ? 'marketplace' : $this->pick($channel === 'balcao'
                        ? ['pix' => 40, 'cartao_credito' => 30, 'cartao_debito' => 15, 'dinheiro' => 15]
                        : ['pix' => 65, 'cartao_credito' => 30, 'boleto' => 5]),
                    'discount_cents' => ! $marketplace && mt_rand(1, 100) <= 20 ? (int) round($subtotal * 0.1) : 0,
                    'shipping_cents' => in_array($channel, ['whatsapp', 'instagram'], true) && mt_rand(1, 100) <= 60 ? mt_rand(12, 25) * 100 : 0,
                    'fee_cents' => $marketplace ? (int) round($subtotal * self::FEES[$channel]) : 0,
                    'placed_at' => $placedAt,
                ]);
                $count++;

                if ($awaiting) {
                    continue;
                }
                if (mt_rand(1, 100) <= 5) {
                    Carbon::setTestNow($placedAt->copy()->addHours(mt_rand(2, 30))->min($realNow)->max($placedAt));
                    $orders->cancel($order, $this->pick(['cliente desistiu' => 50, 'sem estoque do tamanho' => 30, 'endereço não atendido' => 20]));

                    continue;
                }
                $this->progress($order, $placedAt, $age);
            }
        }

        // Inventário de hoje: algumas variantes acabando, para o alerta de estoque baixo aparecer.
        Carbon::setTestNow($realNow);
        foreach (['CAM-BAS-M-PRETO', 'VES-TUB-M-PRETO', 'CAL-JEA-40', 'BON-CUR-BEGE'] as $i => $sku) {
            $level = StockLevel::where('variant_id', $variants[$sku]->id)->first();
            $stock->count($variants[$sku], ($level?->reserved ?? 0) + $i + 1, 'Contagem de inventário (demo)');
        }

        // Notas fiscais: quase todos os pedidos concluídos têm; os mais recentes ficam para o lojista ligar.
        foreach (Order::where('status', 'completed')->get() as $i => $order) {
            if (mt_rand(1, 100) <= 85) {
                $key = '35'.$order->placed_at->format('ym').'12345678000190'.'55'.'001'.str_pad((string) (1000 + $i), 9, '0', STR_PAD_LEFT).'1'.str_pad((string) mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT);
                FiscalDocument::create(['order_id' => $order->id, 'source' => 'manual', 'access_key' => $key.NfeXml::checkDigit($key),
                    'number' => (string) (1000 + $i), 'series' => '1', 'issued_at' => $order->placed_at->copy()->addHours(3), 'total_cents' => $order->total_cents]);
            }
        }

        // Cliente "nasce" na primeira compra (para o indicador de clientes novos).
        foreach (Customer::withMin('orders as first_at', 'placed_at')->get() as $customer) {
            if ($customer->first_at) {
                DB::table('commerce_customers')->where('id', $customer->id)->update(['created_at' => $customer->first_at, 'updated_at' => $customer->first_at]);
            }
        }

        return $count;
    }

    /** Leva o pedido pago até onde ele estaria hoje (separando, enviado, concluído). */
    private function progress(Order $order, Carbon $placedAt, int $age): void
    {
        $shipped = $placedAt->copy()->addDays(1)->setTime(mt_rand(9, 17), 0);
        $done = $placedAt->copy()->addDays(mt_rand(4, 9));

        $changes = match (true) {
            $age >= 10 => ['status' => 'completed', 'shipped_at' => $shipped, 'completed_at' => $done],
            $age >= 3 => ['status' => mt_rand(0, 1) ? 'completed' : 'shipped', 'shipped_at' => $shipped, 'completed_at' => null],
            $age >= 1 => ['status' => 'in_fulfillment'],
            default => [],
        };
        if (($changes['status'] ?? null) === 'shipped' || ($changes['status'] ?? null) === 'completed') {
            $changes['tracking_code'] = 'BR'.mt_rand(100000000, 999999999).'XX';
            if ($changes['status'] === 'completed' && ! $changes['completed_at']) {
                $changes['completed_at'] = $shipped->copy()->addDays(2);
            }
        }
        if ($changes) {
            $order->update($changes);
        }
    }

    private function pick(array $weights): string|int
    {
        $roll = mt_rand(1, array_sum($weights));
        foreach ($weights as $key => $weight) {
            if (($roll -= $weight) <= 0) {
                return $key;
            }
        }

        return array_key_first($weights);
    }

    private function demoOrganizationId(User $user): ?string
    {
        $orgIds = Membership::where('user_id', $user->id)->where('role', 'owner')->pluck('organization_id');

        return Store::withoutGlobalScopes()->whereIn('organization_id', $orgIds)->where('name', self::STORE_NAME)->value('organization_id');
    }

    /** Apaga só a loja demo (e tudo dela). Nunca toca em outra organização. */
    private function purge(string $organizationId): void
    {
        DB::transaction(function () use ($organizationId) {
            foreach ([
                'commerce_fiscal_documents', 'commerce_listing_variants', 'commerce_listings', 'commerce_channel_connections',
                'commerce_order_items', 'commerce_orders', 'commerce_customers', 'commerce_stock_movements', 'commerce_stock_levels',
                'commerce_kit_components', 'commerce_product_images', 'commerce_variants', 'commerce_products', 'commerce_categories',
                'files', 'invitations', 'memberships', 'subscriptions', 'commerce_stores', 'audit_entries',
                // O que a Foxy AI e o Foxy Admin possam ter ligado à loja demo (promoções, créditos, conversas...).
                'ai_promotion_grants', 'ai_credit_grants', 'ai_subscriptions', 'assistant_preferences', 'ai_insights',
                'usage_records', 'conversations', 'usage_counters', 'api_clients', 'host_profiles',
            ] as $table) {
                if ($table === 'commerce_categories') {
                    DB::table($table)->where('organization_id', $organizationId)->whereNotNull('parent_id')->delete();
                }
                DB::table($table)->where('organization_id', $organizationId)->delete();
            }
            $organization = DB::table('organizations')->where('id', $organizationId)->first();
            DB::table('product_accounts')->where('integration_id', $organization->integration_id)->where('external_ref', $organization->external_ref)->delete();
            DB::table('organizations')->where('id', $organizationId)->delete();
        });
        $this->info('Loja demo anterior apagada.');
    }
}
