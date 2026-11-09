<?php

namespace Tests\Feature\Commerce;

use App\Commerce\Catalog\CatalogService;
use App\Commerce\Catalog\Variant;
use App\Commerce\Inventory\StockChanged;
use App\Commerce\Inventory\StockLevel;
use App\Commerce\Inventory\StockMovement;
use App\Commerce\Inventory\StockService;
use App\Commerce\Stores\OpenStore;
use App\Commerce\Stores\Store;
use App\Models\User;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Memberships\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

/**
 * Foxy Commerce, fatia 4 (docs/09, seção 5): estoque único, só por movimento; kit pelas peças; reserva;
 * saldo negativo permitido e sinalizado.
 */
class StockTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    /** @var array<string, Variant> SKU => variante */
    private array $v = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->store = app(OpenStore::class)->open($this->owner, 'Moda da Ana');

        $this->inStore(function () {
            $catalog = app(CatalogService::class);
            $catalog->create(['name' => 'Camiseta', 'price_cents' => 7990, 'sku' => 'CAM'], ['Tamanho'], [['P', 'M']]);
            $catalog->create(['name' => 'Boné', 'price_cents' => 4990, 'sku' => 'BONE'], [], []);
            $catalog->createKit(['name' => 'Kit Verão', 'price_cents' => 11990, 'sku' => 'KIT'], [Variant::where('sku', 'CAM-M')->value('id') => 2, Variant::where('sku', 'BONE')->value('id') => 1]);
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

    private function stock(): StockService
    {
        return app(StockService::class);
    }

    private function level(string $sku): ?StockLevel
    {
        return $this->inStore(fn () => StockLevel::where('variant_id', $this->v[$sku]->id)->first());
    }

    private function available(string $sku): int
    {
        return $this->inStore(fn () => $this->stock()->availability(collect([$this->v[$sku]]))[$this->v[$sku]->id]);
    }

    public function test_entrada_em_lote_pela_tela(): void
    {
        $this->actingAs($this->owner)->post($this->url('/app/estoque/entrada'), [
            'reason' => 'NF 1234',
            'items' => [['sku' => 'cam-m', 'quantity' => 10], ['sku' => 'BONE', 'quantity' => 4], ['sku' => '', 'quantity' => '']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(10, $this->level('CAM-M')->on_hand);
        $this->assertSame(4, $this->level('BONE')->on_hand);
        $movement = $this->inStore(fn () => StockMovement::where('variant_id', $this->v['CAM-M']->id)->sole());
        $this->assertSame('entrada', $movement->type);
        $this->assertSame('NF 1234', $movement->reason);
        $this->assertSame((string) $this->owner->id, $movement->user_id);

        // SKU inexistente: nada é gravado.
        $this->post($this->url('/app/estoque/entrada'), ['items' => [['sku' => 'BONE', 'quantity' => 1], ['sku' => 'XXX', 'quantity' => 1]]])->assertSessionHasErrors('items');
        $this->assertSame(4, $this->level('BONE')->on_hand);

        // Aba de entrada: catálogo para achar o SKU (sem kits) e as últimas entradas.
        $this->get($this->url('/app/estoque?aba=entrada'))->assertOk()
            ->assertSee('Últimas entradas')->assertSee('NF 1234')->assertSee('"CAM-M"', false)->assertDontSee('"KIT"', false);
    }

    public function test_contagem_saida_e_minimo(): void
    {
        $id = $this->v['CAM-P']->id;
        $this->actingAs($this->owner)->post($this->url("/app/estoque/{$id}/ajuste"), ['type' => 'entrada', 'quantity' => 8])->assertSessionHasNoErrors();
        $this->post($this->url("/app/estoque/{$id}/ajuste"), ['type' => 'saida', 'quantity' => 1, 'reason' => 'avaria'])->assertSessionHasNoErrors();
        $this->post($this->url("/app/estoque/{$id}/ajuste"), ['type' => 'ajuste', 'quantity' => 5])->assertSessionHasNoErrors();

        $this->assertSame(5, $this->level('CAM-P')->on_hand);
        $count = $this->inStore(fn () => StockMovement::where('variant_id', $id)->where('type', 'ajuste')->sole());
        $this->assertSame(-2, $count->quantity);

        $this->post($this->url("/app/estoque/{$id}/minimo"), ['min_stock' => 5])->assertSessionHasNoErrors();
        $this->get($this->url('/app/estoque?filtro=baixo'))->assertOk()->assertSee('CAM-P')->assertSee('Baixo');
        $this->get($this->url('/app'))->assertSee('Estoque baixo ou negativo');
        $this->get($this->url("/app/estoque/{$id}"))->assertOk()->assertSee('Contagem')->assertSee('avaria');
    }

    public function test_kit_tem_o_disponivel_das_pecas_e_a_venda_baixa_cada_peca(): void
    {
        $this->inStore(function () {
            $this->stock()->receive($this->v['CAM-M'], 5);
            $this->stock()->receive($this->v['BONE'], 10);
        });
        $this->assertSame(2, $this->available('KIT')); // 5 camisetas ÷ 2 = 2 kits

        $this->inStore(fn () => $this->stock()->sell($this->v['KIT'], 2, 'pedido-1'));
        $this->assertSame(1, $this->level('CAM-M')->on_hand);
        $this->assertSame(8, $this->level('BONE')->on_hand);
        $this->assertSame(0, $this->available('KIT'));
        $this->assertNull($this->level('KIT'));

        // Kit não recebe entrada nem contagem.
        $this->expectException(ValidationException::class);
        $this->inStore(fn () => $this->stock()->receive($this->v['KIT'], 1));
    }

    public function test_reserva_tira_do_disponivel_e_a_venda_solta_a_reserva(): void
    {
        $this->inStore(function () {
            $this->stock()->receive($this->v['BONE'], 3);
            $this->stock()->reserve($this->v['BONE'], 2, 'pedido-2');
        });
        $this->assertSame(1, $this->available('BONE'));

        $this->inStore(fn () => $this->stock()->sell($this->v['BONE'], 2, 'pedido-2', fromReservation: true));
        $this->assertSame(1, $this->level('BONE')->on_hand);
        $this->assertSame(0, $this->level('BONE')->reserved);

        $this->inStore(fn () => $this->stock()->restock($this->v['BONE'], 1, 'pedido-2', 'devolucao'));
        $this->assertSame(2, $this->level('BONE')->on_hand);
    }

    public function test_venda_sem_estoque_deixa_negativo_e_aparece_em_destaque(): void
    {
        $this->inStore(fn () => $this->stock()->sell($this->v['CAM-P'], 1, 'pedido-ml'));
        $this->assertSame(-1, $this->level('CAM-P')->on_hand);

        $this->actingAs($this->owner)->get($this->url('/app/estoque?filtro=negativo'))->assertOk()->assertSee('CAM-P')->assertSee('Negativo')->assertDontSee('BONE');
    }

    public function test_avisa_uma_vez_com_as_variantes_e_os_kits_afetados(): void
    {
        Event::fake([StockChanged::class]);
        $this->inStore(fn () => $this->stock()->sell($this->v['KIT'], 1));

        Event::assertDispatchedTimes(StockChanged::class, 1);
        Event::assertDispatched(StockChanged::class, function (StockChanged $e) {
            sort($e->variantIds);
            $expected = [$this->v['CAM-M']->id, $this->v['BONE']->id, $this->v['KIT']->id];
            sort($expected);

            return $e->organizationId === $this->store->organization_id && $e->variantIds === $expected;
        });
    }

    public function test_movimento_nao_se_altera(): void
    {
        $this->inStore(fn () => $this->stock()->receive($this->v['BONE'], 1));
        $movement = $this->inStore(fn () => StockMovement::sole());

        $this->expectException(LogicException::class);
        $movement->delete();
    }

    public function test_atendente_consulta_mas_nao_mexe_e_outra_loja_nao_ve(): void
    {
        $attendant = User::factory()->create();
        Membership::create(['organization_id' => $this->store->organization_id, 'user_id' => $attendant->id, 'role' => 'attendant']);

        $this->actingAs($attendant)->get($this->url('/app/estoque'))->assertOk()->assertSee('CAM-P')->assertDontSee('Entrada de mercadoria');
        $this->actingAs($attendant)->get($this->url('/app/estoque?aba=entrada'))->assertOk()->assertDontSee('Últimas entradas');
        $this->actingAs($attendant)->post($this->url("/app/estoque/{$this->v['BONE']->id}/ajuste"), ['type' => 'entrada', 'quantity' => 5])->assertForbidden();

        $bia = User::factory()->create();
        app(OpenStore::class)->open($bia, 'Loja da Bia');
        $this->actingAs($bia)->get($this->url("/app/estoque/{$this->v['BONE']->id}"))->assertNotFound();
        $this->actingAs($bia)->post($this->url('/app/estoque/entrada'), ['items' => [['sku' => 'BONE', 'quantity' => 5]]])->assertSessionHasErrors('items');
        $this->assertNull($this->level('BONE'));
    }
}
