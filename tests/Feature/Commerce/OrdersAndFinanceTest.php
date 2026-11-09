<?php

namespace Tests\Feature\Commerce;

use App\Commerce\Catalog\CatalogService;
use App\Commerce\Catalog\Variant;
use App\Commerce\Console\CreateDemo;
use App\Commerce\Insights\FinanceReport;
use App\Commerce\Inventory\StockLevel;
use App\Commerce\Inventory\StockMovement;
use App\Commerce\Inventory\StockService;
use App\Commerce\Orders\Customer;
use App\Commerce\Orders\Order;
use App\Commerce\Orders\OrderService;
use App\Commerce\Stores\OpenStore;
use App\Commerce\Stores\Store;
use App\Models\User;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Memberships\Membership;
use App\Platform\Organizations\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Foxy Commerce, fatia 5 (docs/09): pedidos com estoque junto, clientes, painel financeiro com filtros e a loja demo.
 */
class OrdersAndFinanceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    /** @var array<string, Variant> */
    private array $v = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->store = app(OpenStore::class)->open($this->owner, 'Moda da Ana');

        $this->inStore(function () {
            $catalog = app(CatalogService::class);
            $catalog->create(['name' => 'Camiseta', 'price_cents' => 8000, 'cost_cents' => 3000, 'sku' => 'CAM'], ['Tamanho'], [['P', 'M']]);
            $catalog->create(['name' => 'Boné', 'price_cents' => 5000, 'cost_cents' => 2000, 'sku' => 'BONE'], [], []);
            foreach (Variant::all() as $variant) {
                app(StockService::class)->receive($variant, 10);
            }
            $this->v = Variant::all()->keyBy('sku')->all();
        });
    }

    private function url(string $path): string
    {
        return 'http://'.config('foxy.hosts.commerce').$path;
    }

    private function inStore(callable $callback): mixed
    {
        return app(ContextStore::class)->run(ExecutionContext::forUser($this->store->organization, $this->owner->id), $callback);
    }

    private function onHand(string $sku): int
    {
        return $this->inStore(fn () => StockLevel::where('variant_id', $this->v[$sku]->id)->value('on_hand'));
    }

    private function available(string $sku): int
    {
        return $this->inStore(fn () => app(StockService::class)->availability(collect([$this->v[$sku]]))[$this->v[$sku]->id]);
    }

    private function place(array $data): Order
    {
        return $this->inStore(fn () => app(OrderService::class)->place($data));
    }

    public function test_pedido_pago_pela_tela_baixa_o_estoque_e_cria_o_cliente(): void
    {
        $this->actingAs($this->owner)->post($this->url('/app/pedidos'), [
            'channel' => 'whatsapp',
            'customer' => ['name' => 'Bruna Lima', 'phone' => '11988887777'],
            'items' => [['sku' => 'cam-m', 'quantity' => 2, 'price' => ''], ['sku' => 'BONE', 'quantity' => 1, 'price' => '45,00'], ['sku' => '']],
            'discount' => '10', 'shipping' => '15,00', 'paid' => '1', 'payment_method' => 'pix',
        ])->assertSessionHasNoErrors();

        $order = $this->inStore(fn () => Order::with('items', 'customer')->sole());
        $this->assertSame(1, $order->number);
        $this->assertSame('paid', $order->status);
        $this->assertSame(20500, $order->subtotal_cents); // 2×80 + 45
        $this->assertSame(20500 - 1000 + 1500, $order->total_cents);
        $this->assertSame(8000, $order->cost_cents); // 2×30 + 20
        $this->assertSame(21000 - 1500 - 0 - 8000, $order->profitCents());
        $this->assertSame('Bruna Lima', $order->customer->name);
        $this->assertSame('CAM-M', $order->items[0]->sku);
        $this->assertSame(8, $this->onHand('CAM-M'));
        $this->assertSame(9, $this->onHand('BONE'));

        $this->get($this->url("/app/pedidos/{$order->id}"))->assertOk()->assertSee('Pedido #1')->assertSee('Bruna Lima');
        $this->get($this->url('/app/pedidos'))->assertOk()->assertSee('#1');
    }

    public function test_nao_pago_reserva_e_depois_baixa_ao_confirmar(): void
    {
        $order = $this->place(['channel' => 'balcao', 'items' => [['variant' => $this->v['BONE'], 'quantity' => 3]]]);
        $this->assertSame('awaiting_payment', $order->status);
        $this->assertSame(10, $this->onHand('BONE'));
        $this->assertSame(7, $this->available('BONE'));

        $this->actingAs($this->owner)->post($this->url("/app/pedidos/{$order->id}/pago"), ['payment_method' => 'dinheiro'])->assertSessionHasNoErrors();
        $this->assertSame(7, $this->onHand('BONE'));
        $this->assertSame(7, $this->available('BONE'));
        $this->assertSame('dinheiro', $order->fresh()->payment_method);
    }

    public function test_andamento_do_pedido_e_cancelamento_devolve_ao_estoque(): void
    {
        $order = $this->place(['channel' => 'whatsapp', 'paid' => true, 'items' => [['variant' => $this->v['CAM-P'], 'quantity' => 2]]]);
        $this->actingAs($this->owner);

        $this->post($this->url("/app/pedidos/{$order->id}/andamento"), ['status' => 'completed'])->assertSessionHasNoErrors();
        $this->post($this->url("/app/pedidos/{$order->id}/andamento"), ['status' => 'shipped'])->assertSessionHasErrors('status');
        $this->post($this->url("/app/pedidos/{$order->id}/cancelar"))->assertSessionHasErrors('status');

        $other = $this->place(['channel' => 'whatsapp', 'paid' => true, 'items' => [['variant' => $this->v['CAM-P'], 'quantity' => 2]]]);
        $this->assertSame(2, $other->number);
        $this->post($this->url("/app/pedidos/{$other->id}/andamento"), ['status' => 'shipped', 'tracking_code' => 'BR123'])->assertSessionHasNoErrors();
        $this->assertSame('BR123', $other->fresh()->tracking_code);
        $this->assertSame(6, $this->onHand('CAM-P'));

        $this->post($this->url("/app/pedidos/{$other->id}/cancelar"), ['reason' => 'devolvido'])->assertSessionHasNoErrors();
        $this->assertSame('canceled', $other->fresh()->status);
        $this->assertSame(8, $this->onHand('CAM-P'));
    }

    public function test_editar_dados_do_pedido(): void
    {
        $order = $this->place(['channel' => 'whatsapp', 'items' => [['variant' => $this->v['CAM-P'], 'quantity' => 2]]]);
        $this->actingAs($this->owner);

        $this->get($this->url("/app/pedidos/{$order->id}"))->assertSee('Editar dados do pedido');
        $this->post($this->url("/app/pedidos/{$order->id}/editar"), ['notes' => 'Entregar à tarde', 'payment_method' => 'dinheiro', 'discount' => '5,00', 'shipping' => '12,50'])->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame('Entregar à tarde', $order->notes);
        $this->assertSame('dinheiro', $order->payment_method);
        $this->assertSame($order->subtotal_cents - 500 + 1250, $order->total_cents);

        // Pedido de marketplace: frete e desconto vêm do canal.
        $ml = $this->place(['channel' => 'mercadolivre', 'paid' => true, 'payment_method' => 'marketplace', 'shipping_cents' => 2000, 'items' => [['variant' => $this->v['CAM-P'], 'quantity' => 1]]]);
        $this->post($this->url("/app/pedidos/{$ml->id}/editar"), ['notes' => 'ok', 'shipping' => '0', 'payment_method' => 'pix'])->assertSessionHasNoErrors();
        $this->assertSame(2000, $ml->fresh()->shipping_cents);
        $this->assertSame('marketplace', $ml->fresh()->payment_method);
        $this->assertSame('ok', $ml->fresh()->notes);

        $this->post($this->url("/app/pedidos/{$order->id}/cancelar"))->assertSessionHasNoErrors();
        $this->post($this->url("/app/pedidos/{$order->id}/editar"), ['notes' => 'x'])->assertSessionHasErrors('order');
    }

    public function test_trocar_uma_peca_do_pedido(): void
    {
        $order = $this->place(['channel' => 'whatsapp', 'paid' => true, 'items' => [['variant' => $this->v['CAM-P'], 'quantity' => 2], ['variant' => $this->v['BONE'], 'quantity' => 1]]]);
        $item = $this->inStore(fn () => $order->items()->where('sku', 'CAM-P')->sole());
        $this->actingAs($this->owner);

        $this->get($this->url("/app/pedidos/{$order->id}"))->assertSee('Trocar peça')->assertSee('CAM-M');

        // Troca 1 das 2 camisetas P por M: o item se divide, P volta 1, M sai 1, total igual.
        $this->post($this->url("/app/pedidos/{$order->id}/trocar"), ['item_id' => $item->id, 'sku' => 'cam-m', 'quantity' => 1])->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame(9, $this->onHand('CAM-P'));
        $this->assertSame(9, $this->onHand('CAM-M'));
        $this->assertSame(['BONE' => 1, 'CAM-M' => 1, 'CAM-P' => 1], $this->inStore(fn () => $order->items()->pluck('quantity', 'sku')->sortKeys()->all()));
        $this->assertSame(8000 * 2 + 5000, $order->total_cents);
        $this->assertStringContainsString('1× CAM-P → CAM-M', $order->notes);
        $this->assertSame('troca', $this->inStore(fn () => StockMovement::where('variant_id', $this->v['CAM-P']->id)->latest('id')->value('type')));

        // Pedido digitado: dá para mudar o preço da peça nova. Quantidade maior que o item é recusada.
        $this->post($this->url("/app/pedidos/{$order->id}/trocar"), ['item_id' => $item->id, 'sku' => 'BONE', 'quantity' => 1, 'price' => '45,00'])->assertSessionHasNoErrors();
        $this->assertSame(8000 + 5000 + 4500, $order->fresh()->total_cents);
        $bone = $this->inStore(fn () => $order->items()->where('sku', 'BONE')->first());
        $this->post($this->url("/app/pedidos/{$order->id}/trocar"), ['item_id' => $bone->id, 'sku' => 'CAM-P', 'quantity' => 5])->assertSessionHasErrors('exchange');
        $this->post($this->url("/app/pedidos/{$order->id}/trocar"), ['item_id' => $bone->id, 'sku' => 'XYZ', 'quantity' => 1])->assertSessionHasErrors('exchange');

        // Ainda sem pagamento: troca só a reserva.
        $pending = $this->place(['channel' => 'whatsapp', 'items' => [['variant' => $this->v['CAM-P'], 'quantity' => 1]]]);
        $this->post($this->url("/app/pedidos/{$pending->id}/trocar"), ['item_id' => $this->inStore(fn () => $pending->items()->value('id')), 'sku' => 'CAM-M', 'quantity' => 1])->assertSessionHasNoErrors();
        $this->assertSame(10, $this->onHand('CAM-P'));
        $this->assertSame(8, $this->available('CAM-M')); // 9 em estoque (1 saiu na primeira troca) − 1 reservada
    }

    public function test_reserva_vencida_cancela_e_solta_as_pecas(): void
    {
        $order = $this->place(['channel' => 'whatsapp', 'items' => [['variant' => $this->v['BONE'], 'quantity' => 4]]]);
        $this->travel(49)->hours();

        $this->artisan('commerce:reservas')->assertSuccessful();
        $this->assertSame('canceled', $order->fresh()->status);
        $this->assertSame(10, $this->available('BONE'));
    }

    public function test_atendente_registra_pedido_mas_nao_cancela_nem_ve_o_financeiro(): void
    {
        $attendant = User::factory()->create();
        Membership::create(['organization_id' => $this->store->organization_id, 'user_id' => $attendant->id, 'role' => 'attendant']);
        $order = $this->place(['channel' => 'balcao', 'paid' => true, 'items' => [['variant' => $this->v['BONE'], 'quantity' => 1]]]);

        $this->actingAs($attendant)->get($this->url('/app/pedidos/novo'))->assertOk()->assertSee('+ Adicionar item')->assertSee('"sku":"CAM-P"', false)->assertDontSee('Produto que não está cadastrado');
        $this->actingAs($attendant)->post($this->url("/app/pedidos/{$order->id}/cancelar"))->assertForbidden();
        $this->actingAs($attendant)->get($this->url('/app'))->assertOk()->assertSee('Para separar e enviar')->assertDontSee('Lucro bruto');
    }

    public function test_pedido_e_cliente_de_outra_loja_nao_abrem(): void
    {
        $order = $this->place(['channel' => 'balcao', 'paid' => true, 'customer' => ['name' => 'Cliente Ana'], 'items' => [['variant' => $this->v['BONE'], 'quantity' => 1]]]);
        $bia = User::factory()->create();
        app(OpenStore::class)->open($bia, 'Loja da Bia');

        $this->actingAs($bia)->get($this->url("/app/pedidos/{$order->id}"))->assertNotFound();
        $this->actingAs($bia)->get($this->url("/app/clientes/{$order->customer_id}"))->assertNotFound();
        $this->actingAs($bia)->get($this->url('/app/clientes'))->assertDontSee('Cliente Ana');
        $this->actingAs($bia)->get($this->url('/app'))->assertOk()->assertSee('R$ 0,00');
    }

    public function test_painel_financeiro_soma_e_filtra_por_canal(): void
    {
        $this->place(['channel' => 'mercadolivre', 'paid' => true, 'payment_method' => 'marketplace', 'fee_cents' => 1600, 'items' => [['variant' => $this->v['CAM-M'], 'quantity' => 1, 'unit_price_cents' => 10000]]]);
        $this->place(['channel' => 'whatsapp', 'paid' => true, 'payment_method' => 'pix', 'customer' => ['name' => 'Carla'], 'items' => [['variant' => $this->v['BONE'], 'quantity' => 2]]]);
        $canceled = $this->place(['channel' => 'whatsapp', 'paid' => true, 'items' => [['variant' => $this->v['BONE'], 'quantity' => 1]]]);
        $this->inStore(fn () => app(OrderService::class)->cancel($canceled));
        $this->place(['channel' => 'balcao', 'items' => [['variant' => $this->v['CAM-P'], 'quantity' => 1]]]); // aguardando

        $k = $this->inStore(fn () => (new FinanceReport([]))->kpis());
        $this->assertSame(20000, $k['revenue']);
        $this->assertSame(2, $k['orders']);
        $this->assertSame(10000, $k['ticket']);
        $this->assertSame(1600, $k['fees']);
        $this->assertSame(7000, $k['cost']);
        $this->assertSame(20000 - 1600 - 7000, $k['profit']);
        $this->assertSame(1, $k['canceled_count']);
        $this->assertSame(8000, $k['awaiting_cents']);

        $ml = $this->inStore(fn () => (new FinanceReport(['canal' => 'mercadolivre']))->kpis());
        $this->assertSame(10000, $ml['revenue']);

        $this->actingAs($this->owner)->get($this->url('/app'))->assertOk()
            ->assertSee('Faturamento')->assertSee('R$ 200,00')->assertSee('Mercado Livre')->assertSee('Lucro bruto')->assertSee('Carla');
        $this->get($this->url('/app?periodo=personalizado&de='.now()->subDays(3)->format('Y-m-d').'&ate='.now()->format('Y-m-d').'&canal=whatsapp'))
            ->assertOk()->assertSee('R$ 100,00');
        $this->get($this->url('/app?periodo=invalido'))->assertSessionHasErrors('periodo');
    }

    public function test_planilha_nao_vira_formula_no_excel(): void
    {
        $this->place(['channel' => 'whatsapp', 'paid' => true, 'customer' => ['name' => '=HYPERLINK("http://x")'], 'fee_cents' => 99999, 'items' => [['variant' => $this->v['BONE'], 'quantity' => 1]]]);

        $csv = $this->actingAs($this->owner)->get($this->url('/app/pedidos/exportar'))->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString(';-969,99;', $csv); // lucro negativo continua número
    }

    public function test_planilha_dos_pedidos(): void
    {
        $this->place(['channel' => 'shopee', 'paid' => true, 'fee_cents' => 1000, 'items' => [['variant' => $this->v['BONE'], 'quantity' => 1]]]);

        $csv = $this->actingAs($this->owner)->get($this->url('/app/pedidos/exportar'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Pedido;Data;Canal', $csv);
        $this->assertStringContainsString('Shopee', $csv);
        $this->assertStringContainsString('50,00', $csv);
    }

    public function test_clientes_com_total_comprado(): void
    {
        $this->place(['channel' => 'whatsapp', 'paid' => true, 'customer' => ['name' => 'Daniela Souza', 'phone' => '1190000'], 'items' => [['variant' => $this->v['BONE'], 'quantity' => 2]]]);
        $customer = $this->inStore(fn () => Customer::sole());

        $this->actingAs($this->owner)->get($this->url('/app/clientes'))->assertOk()->assertSee('Daniela Souza')->assertSee('R$ 100,00');
        $this->get($this->url("/app/clientes/{$customer->id}"))->assertOk()->assertSee('Ticket médio');
        $this->post($this->url("/app/clientes/{$customer->id}"), ['name' => 'Daniela S.', 'state' => 'SP'])->assertSessionHasNoErrors();
        $this->assertSame('Daniela S.', $customer->fresh()->name);
    }

    public function test_loja_demo_com_meses_de_vendas(): void
    {
        Storage::fake(config('commerce.images_disk'));
        $this->artisan('commerce:demo', ['--dias' => 20, '--senha' => 'demo-senha-123'])->assertSuccessful();

        $demo = User::where('email', 'demo@foxy.net.br')->firstOrFail();
        $store = Store::withoutGlobalScopes()->where('name', CreateDemo::STORE_NAME)->firstOrFail();
        $orders = Order::withoutGlobalScopes()->where('organization_id', $store->organization_id);
        $this->assertGreaterThan(20, $orders->count());
        $this->assertTrue((clone $orders)->where('channel', 'mercadolivre')->exists());
        $this->assertTrue((clone $orders)->where('status', 'completed')->exists());
        $this->assertTrue((clone $orders)->where('placed_at', '<', now()->subDays(15))->exists());

        $this->post($this->url('/entrar'), ['email' => 'demo@foxy.net.br', 'password' => 'demo-senha-123'])->assertRedirect($this->url('/app'));
        $this->get($this->url('/app?periodo=90d'))->assertOk()->assertSee('Mercado Livre')->assertSee('Mais vendidos')->assertDontSee('só para consulta');
        $this->get($this->url('/app/estoque'))->assertOk();
        $this->get($this->url('/'.$store->slug))->assertOk()->assertSee('Camiseta Básica Algodão')->assertSee('.svg');

        // Sem --fresh não duplica; com --fresh recria só a demo.
        $this->artisan('commerce:demo', ['--dias' => 10])->assertFailed();
        $this->artisan('commerce:demo', ['--dias' => 10, '--fresh' => true, '--senha' => 'outra-senha-456'])->assertSuccessful();
        $this->assertSame(1, Store::withoutGlobalScopes()->where('name', CreateDemo::STORE_NAME)->count());
        $this->assertTrue(Store::withoutGlobalScopes()->where('name', 'Moda da Ana')->exists());
        $this->assertNull(Organization::find($store->organization_id));
        $this->assertSame(1, $demo->fresh()->memberships()->count());
    }
}
