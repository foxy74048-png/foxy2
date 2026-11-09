<?php

namespace Tests\Feature\Commerce;

use App\Commerce\Catalog\CatalogService;
use App\Commerce\Catalog\Variant;
use App\Commerce\Insights\Reports;
use App\Commerce\Inventory\StockService;
use App\Commerce\Orders\OrderService;
use App\Commerce\Stores\OpenStore;
use App\Commerce\Stores\Store;
use App\Models\User;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Memberships\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Foxy Commerce, fatia 11 (docs/09): relatórios mês a mês, estoque parado e giro, curva ABC.
 */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    /** @var array<string, Variant> */
    private array $v = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 15)->setTime(12, 0));
        $this->owner = User::factory()->create();
        $this->store = app(OpenStore::class)->open($this->owner, 'Moda da Ana');

        $this->inStore(function () {
            $catalog = app(CatalogService::class);
            $catalog->create(['name' => 'Camiseta', 'price_cents' => 10000, 'cost_cents' => 4000, 'sku' => 'CAM'], [], []);
            $catalog->create(['name' => 'Boné', 'price_cents' => 1000, 'cost_cents' => 500, 'sku' => 'BONE'], [], []);
            $catalog->create(['name' => 'Bolsa', 'price_cents' => 20000, 'cost_cents' => 9000, 'sku' => 'BOLSA'], [], []);
            $this->v = Variant::all()->keyBy('sku')->all();
            $stock = app(StockService::class);
            $stock->receive($this->v['CAM'], 40);
            $stock->receive($this->v['BONE'], 100);
            $stock->receive($this->v['BOLSA'], 10);

            $sell = fn (string $sku, int $qty, string $date) => app(OrderService::class)->place(['channel' => 'whatsapp', 'paid' => true, 'placed_at' => now()->parse($date), 'items' => [['variant' => $this->v[$sku], 'quantity' => $qty]]]);
            $sell('CAM', 2, '2026-10-10 10:00');   // outubro: R$ 200
            $sell('CAM', 3, '2026-11-05 10:00');   // novembro: R$ 300
            $sell('CAM', 30, '2026-11-10 10:00');  // + R$ 3.000 → a camiseta acaba logo
            $sell('BONE', 1, '2026-11-12 10:00');  // + R$ 10
            // Bolsa: nunca vendeu → parada.
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

    public function test_mes_a_mes_com_variacao_sobre_o_anterior(): void
    {
        $months = $this->inStore(fn () => app(Reports::class)->monthly(12));

        $this->assertCount(12, $months);
        $nov = $months->last();
        $oct = $months[10];
        $this->assertSame(20000, $oct['revenue']);
        $this->assertSame(331000, $nov['revenue']);
        $this->assertSame(3, $nov['orders']);
        $this->assertSame(1555.0, $nov['delta']); // (3.310 − 200) ÷ 200
        $this->assertNull($months[0]['delta'] ?? null);

        $this->actingAs($this->owner)->get($this->url('/app/relatorios'))->assertOk()->assertSee('nov/2026 (até hoje)')->assertSee('▲ 1.555,0%');
    }

    public function test_estoque_parado_acabando_e_dinheiro_parado(): void
    {
        $rows = $this->inStore(fn () => app(Reports::class)->stock(30))->keyBy(fn ($r) => $r['variant']->sku);

        $this->assertSame('parado', $rows['BOLSA']['status']);
        $this->assertSame(90000, $rows['BOLSA']['stuck_cents']); // 10 × R$ 90
        $this->assertNull($rows['BOLSA']['last_sale']);
        $this->assertSame('acabando', $rows['CAM']['status']); // 5 restantes, 33 vendidas em 30 dias → ~4 dias
        $this->assertSame(4, $rows['CAM']['coverage']);
        $this->assertSame('lento', $rows['BONE']['status']); // 99 restantes, 1 por mês

        $this->actingAs($this->owner)->get($this->url('/app/relatorios?aba=estoque&dias=30'))->assertOk()->assertSee('Dinheiro parado')->assertSee('BOLSA');
        $this->get($this->url('/app/relatorios?aba=estoque&dias=30&situacao=acabando'))->assertSee('CAM')->assertDontSee('BOLSA');

        $csv = $this->get($this->url('/app/relatorios/estoque.csv?dias=30'))->assertOk()->streamedContent();
        $this->assertStringContainsString('BOLSA;Bolsa;10;0', $csv);
    }

    public function test_curva_abc(): void
    {
        $rows = $this->inStore(fn () => app(Reports::class)->abc(now()->subDays(60), now()));

        $this->assertSame(['Camiseta', 'Boné'], $rows->pluck('label')->all());
        $this->assertSame('A', $rows[0]['class']);
        $this->assertSame('C', $rows[1]['class']); // 0,3% do faturamento, depois de 99,7% acumulados
        $this->assertSame(60.0, $rows[0]['margin']);

        $this->actingAs($this->owner)->get($this->url('/app/relatorios?aba=abc&periodo=90d'))->assertOk()->assertSee('Classe A')->assertSee('Camiseta');
        $this->get($this->url('/app/relatorios?aba=abc&periodo=90d&classe=C'))->assertSee('Boné')->assertDontSee('<td>Camiseta</td>', false);
    }

    public function test_toda_lista_e_relatorio_exporta_planilha_e_pdf(): void
    {
        $this->actingAs($this->owner);
        $pages = [
            '/app?periodo=90d' => 'Faturamento', '/app/relatorios' => 'Faturamento', '/app/relatorios?aba=estoque&dias=30' => 'BOLSA',
            '/app/relatorios?aba=abc' => 'Camiseta', '/app/pedidos?periodo=90d' => 'WhatsApp', '/app/produtos' => 'Camiseta', '/app/categorias' => 'Categoria',
            '/app/estoque' => 'BOLSA', '/app/estoque/'.$this->v['CAM']->id => 'Entrada', '/app/clientes' => 'Cliente', '/app/anuncios' => 'Título', '/app/equipe' => $this->owner->email,
        ];

        foreach ($pages as $path => $expected) {
            $this->get($this->url($path))->assertOk()->assertSee('exportar=pdf', false);
            $glue = str_contains($path, '?') ? '&' : '?';

            $csv = $this->get($this->url($path.$glue.'exportar=planilha'))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->streamedContent();
            $this->assertStringContainsString($expected, $csv, "planilha de {$path}");

            $pdf = $this->get($this->url($path.$glue.'exportar=pdf'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', $pdf->getContent(), "pdf de {$path}");
        }

        // Paginação não corta a exportação; filtros valem.
        $csv = $this->get($this->url('/app/estoque?filtro=negativo&exportar=planilha'))->streamedContent();
        $this->assertStringNotContainsString('BOLSA', $csv);
    }

    public function test_so_dono_e_gerente_veem_relatorios(): void
    {
        $attendant = User::factory()->create();
        Membership::create(['organization_id' => $this->store->organization_id, 'user_id' => $attendant->id, 'role' => 'attendant']);

        $this->actingAs($attendant)->get($this->url('/app/relatorios'))->assertForbidden();
        $this->actingAs($attendant)->get($this->url('/app/relatorios/estoque.csv'))->assertForbidden();
    }
}
