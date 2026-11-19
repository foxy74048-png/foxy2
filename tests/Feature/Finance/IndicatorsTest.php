<?php

namespace Tests\Feature\Finance;

use App\Finance\Accounts\Account;
use App\Finance\Budgets\Budget;
use App\Finance\Categories\Category;
use App\Finance\Insights\Indicators;
use App\Finance\Transactions\TransactionService;
use App\Finance\Wallets\OpenWallet;
use App\Finance\Wallets\Wallet;
use App\Models\User;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Memberships\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Foxy Finanças, fatia 6 (docs/11 §4.1): indicadores para economizar e orçamento. Cenário de agosto a novembro,
 * com as contas feitas à mão no comentário de cada número.
 */
class IndicatorsTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private Wallet $wallet;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->ana = User::factory()->create();
        $this->wallet = app(OpenWallet::class)->open($this->ana, 'Pessoal');
        $this->bank = $this->in(fn () => Account::create(['kind' => 'corrente', 'name' => 'Nubank']));
        $this->in(fn () => Account::create(['kind' => 'poupanca', 'name' => 'Poupança', 'opening_balance_cents' => 600000]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function url(string $path): string
    {
        return 'http://'.config('foxy.hosts.financas').$path;
    }

    private function in(callable $fn)
    {
        return app(ContextStore::class)->run(ExecutionContext::forUser($this->wallet->organization, $this->ana->id), $fn);
    }

    private function cat(string $key): string
    {
        return $this->in(fn () => Category::where('system_key', $key)->value('id'));
    }

    private function add(string $date, int $cents, string $description, string $category, string $kind = 'gasto', array $extra = []): void
    {
        $this->in(fn () => app(TransactionService::class)->create(['kind' => $kind, 'amount_cents' => $cents, 'date' => $date, 'description' => $description, 'account_id' => $this->bank->id, 'category_id' => $this->cat($category)] + $extra));
    }

    /**
     * Agosto, setembro e outubro iguais: salário 5.000; mercado 1.000; delivery 200; Netflix 55,90; 10 cafés de 8.
     * Novembro até dia 15: salário 5.000; mercado 600; delivery 600; Netflix 55,90; 5 cafés; Spotify 21,90 (todo mês).
     */
    private function scenario(): void
    {
        foreach (['2026-08', '2026-09', '2026-10'] as $m) {
            $this->add("{$m}-05", 500000, 'Salário', 'salario', 'recebimento');
            $this->add("{$m}-06", 100000, 'Supermercado Assaí', 'mercado');
            $this->add("{$m}-08", 20000, 'iFood', 'delivery');
            $this->add("{$m}-09", 5590, 'Netflix', 'streaming');
            for ($d = 10; $d < 20; $d++) {
                $this->add("{$m}-{$d}", 800, 'Café da esquina', 'lanche');
            }
        }
        $this->add('2026-11-05', 500000, 'Salário', 'salario', 'recebimento');
        $this->add('2026-11-06', 60000, 'Supermercado Assaí', 'mercado');
        $this->add('2026-11-07', 30000, 'iFood', 'delivery');
        $this->add('2026-11-12', 30000, 'iFood', 'delivery');
        $this->add('2026-11-09', 5590, 'Netflix', 'streaming');
        for ($d = 10; $d < 15; $d++) {
            $this->add("2026-11-{$d}", 800, 'Café da esquina', 'lanche');
        }
        $this->add('2026-11-10', 2190, 'Spotify', 'streaming', 'gasto', ['mode' => 'mensal', 'count' => 12]);
    }

    private function indicators(): Indicators
    {
        return $this->in(fn () => Indicators::for(Carbon::parse('2026-11-01')));
    }

    public function test_quanto_guardou_e_o_ritmo_do_mes(): void
    {
        $this->scenario();
        $s = $this->in(fn () => $this->indicators()->summary());

        // Novembro: 600 + 600 + 55,90 + 40 + 21,90 = 1.317,80 de gasto.
        $this->assertSame([500000, 131780, 368220], [$s['income'], $s['expense'], $s['saved']]);
        $this->assertEqualsWithDelta(0.73644, $s['savings_rate'], 0.0001);
        // Antes: 1.000 + 200 + 55,90 + 80 = 1.335,90 por mês.
        $this->assertSame(133590, $s['avg_expense']);
        $this->assertEqualsWithDelta(0.73282, $s['avg_savings_rate'], 0.0001);

        // Dia 15 de 30: 1.317,80 ÷ 15 × 30 = 2.635,60.
        $pace = $this->in(fn () => $this->indicators()->pace());
        $this->assertSame([263560, 8785, 15], [$pace['projected'], $pace['per_day'], $pace['days_left']]);
        $this->assertNull($this->in(fn () => Indicators::for(Carbon::parse('2026-10-01'))->pace()), 'Mês passado não tem ritmo.');
    }

    public function test_o_que_mais_subiu_e_onde_mais_gastou(): void
    {
        $this->scenario();
        $i = $this->indicators();

        // Comer fora: média 280 (200 + 80) → 640 neste mês (600 + 40): subiu 360. Mercado caiu; assinaturas subiu só 21,90.
        $rises = $this->in(fn () => $i->rises());
        $this->assertSame([['Comer fora', 36000]], $rises->map(fn ($r) => [$r['name'], $r['delta']])->all());

        $places = $this->in(fn () => $i->places());
        $this->assertSame(['Supermercado Assaí', 60000], [$places[0]['label'], $places[0]['total']]);
        $this->assertSame(['iFood', 60000, 2], [$places[1]['label'], $places[1]['total'], $places[1]['count']]);
    }

    public function test_gastinhos_assinaturas_reserva_e_cortar_pela_metade(): void
    {
        $this->scenario();
        $i = $this->indicators();

        // Até R$ 30: 5 cafés (40) + Spotify (21,90). Set, out, nov = 80 + 80 + 61,90 = 221,90 → ÷ 3 × 12 = 887,60 por ano.
        $small = $this->in(fn () => $i->smallSpending());
        $this->assertSame([6, 6190, 88760], [$small['count'], $small['total'], $small['per_year']]);
        $this->assertSame('Café da esquina', $small['top'][0]['label']);

        // Netflix: uma vez por mês com o mesmo valor; Spotify: lançado como "todo mês". Mercado variou demais; café é várias vezes no mês.
        $subs = $this->in(fn () => $i->subscriptions());
        $this->assertEqualsCanonicalizing(['Netflix' => 5590, 'Spotify' => 2190], $subs->pluck('monthly', 'label')->all());
        $this->assertSame(5590 * 12, $subs->firstWhere('label', 'Netflix')['yearly']);

        // 6.000 guardados ÷ 1.335,90 por mês = 4,49 meses.
        $reserve = $this->in(fn () => $i->reserve());
        $this->assertEqualsWithDelta(4.49, $reserve['months'], 0.01);

        // Fora do essencial: Comer fora (640) e Assinaturas (77,80) → metade × 12 = 4.306,80.
        $cut = $this->in(fn () => $i->cutInHalf());
        $this->assertSame(['Comer fora', 'Assinaturas'], $cut['categories']->pluck('name')->all());
        $this->assertSame(430680, $cut['per_year']);

        // Mês a mês.
        $evolution = $this->in(fn () => $i->evolution(4));
        $this->assertSame(['ago', 'set', 'out', 'nov'], $evolution->pluck('label')->all());
        $this->assertSame(131780, $evolution->last()['expense']);
    }

    public function test_orcamento_avisa_perto_e_estourado_e_o_limite_do_mes_vence(): void
    {
        $this->scenario();
        $this->actingAs($this->ana)->post($this->url('/app/orcamento'), ['category_id' => $this->cat('delivery'), 'amount' => '500'])->assertSessionHasNoErrors();
        $this->actingAs($this->ana)->post($this->url('/app/orcamento'), ['category_id' => $this->cat('mercado'), 'amount' => '700'])->assertSessionHasNoErrors();
        // Limite de grupo inclui as de dentro: Comer fora = delivery 600 + café 40.
        $this->actingAs($this->ana)->post($this->url('/app/orcamento'), ['category_id' => $this->cat('alimentacao'), 'amount' => '2.000'])->assertSessionHasNoErrors();

        $budgets = $this->in(fn () => $this->indicators()->budgets())->keyBy(fn ($b) => $b['category']->system_key);
        $this->assertSame([60000, 'estourou'], [$budgets['delivery']['spent'], $budgets['delivery']['status']]);
        $this->assertSame('perto', $budgets['mercado']['status']);
        $this->assertSame([64000, 'ok'], [$budgets['alimentacao']['spent'], $budgets['alimentacao']['status']]);

        // Só em novembro, um limite maior para o delivery: vence o de todo mês.
        $this->actingAs($this->ana)->post($this->url('/app/orcamento'), ['category_id' => $this->cat('delivery'), 'amount' => '800', 'only_month' => '2026-11']);
        $budgets = $this->in(fn () => $this->indicators()->budgets())->keyBy(fn ($b) => $b['category']->system_key);
        $this->assertSame([80000, 'ok'], [$budgets['delivery']['limit'], $budgets['delivery']['status']]);
        $this->assertSame(4, $this->in(fn () => Budget::count()));

        $this->actingAs($this->ana)->get($this->url('/app'))->assertSee('Orçamento pedindo atenção')->assertSee('Mercado');
        $this->actingAs($this->ana)->get($this->url('/app/orcamento'))->assertOk()->assertSee('sobram')->assertSee('só em 11/2026')->assertSee('86% usado');

        $this->actingAs($this->ana)->post($this->url('/app/orcamento'), ['category_id' => $this->cat('salario'), 'amount' => '10'])->assertSessionHasErrors('category_id');
        $this->actingAs($this->ana)->post($this->url('/app/orcamento'), ['category_id' => $this->cat('lazer'), 'amount' => '0'])->assertSessionHasErrors('amount');
    }

    public function test_telas_e_exportacao(): void
    {
        $this->scenario();

        $this->actingAs($this->ana)->get($this->url('/app/painel'))->assertOk()
            ->assertSee('Onde dá para economizar')->assertSee('R$ 4.306,80 por ano')->assertSee('R$ 887,60 por ano')
            ->assertSee('4,5 meses')->assertSee('No ritmo atual, o mês fecha em')->assertSee('R$ 2.635,60')->assertSee('Netflix');
        $this->actingAs($this->ana)->get($this->url('/app/painel?mes=2026-10'))->assertOk()->assertDontSee('No ritmo atual');
        $this->actingAs($this->ana)->get($this->url('/app'))->assertSee('74% do que entrou')->assertSee('Ver onde dá para economizar');

        $csv = $this->actingAs($this->ana)->get($this->url('/app/painel?exportar=planilha'))->streamedContent();
        $this->assertStringContainsString('Assinaturas', $csv);
        $this->assertStringContainsString('Netflix', $csv);
        $this->actingAs($this->ana)->get($this->url('/app/painel?exportar=pdf'))->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->ana)->get($this->url('/app/orcamento?exportar=planilha'))->assertOk();

        // Sem limite ainda: a tela sugere começar pelos grupos onde mais gasta.
        $this->actingAs($this->ana)->get($this->url('/app/orcamento'))->assertSee('Sugestão para começar')->assertSee('Mercado');

        // Quem só vê enxerga o painel e o orçamento, mas não mexe.
        $viewer = User::factory()->create();
        Membership::create(['organization_id' => $this->wallet->organization_id, 'user_id' => $viewer->id, 'role' => 'viewer']);
        $this->actingAs($viewer)->get($this->url('/app/painel'))->assertOk();
        $this->actingAs($viewer)->get($this->url('/app/orcamento'))->assertOk()->assertDontSee('Salvar limite');
        $this->actingAs($viewer)->post($this->url('/app/orcamento'), ['category_id' => $this->cat('lazer'), 'amount' => '10'])->assertForbidden();
    }

    public function test_sem_historico_nada_e_novo_e_aluguel_nao_e_assinatura(): void
    {
        // Primeiro mês de uso: aluguel "todo mês" e um streaming "todo mês".
        $this->add('2026-11-05', 150000, 'Aluguel', 'aluguel', 'gasto', ['mode' => 'mensal', 'count' => 12]);
        $this->add('2026-11-06', 3990, 'Max', 'streaming', 'gasto', ['mode' => 'mensal', 'count' => 12]);
        $i = $this->indicators();

        $this->assertFalse($this->in(fn () => $i->hasHistory()));
        $this->assertCount(0, $this->in(fn () => $i->rises()));
        $this->assertSame(['Max'], $this->in(fn () => $i->subscriptions())->pluck('label')->all());

        $this->actingAs($this->ana)->get($this->url('/app/painel'))->assertSee('Ainda não há meses anteriores para comparar');
    }

    public function test_sem_dados_nao_quebra(): void
    {
        $this->actingAs($this->ana)->get($this->url('/app/painel'))->assertOk()->assertSee('Nenhum gasto neste mês.');
        $this->actingAs($this->ana)->get($this->url('/app/orcamento'))->assertOk();
        $this->actingAs($this->ana)->get($this->url('/app'))->assertOk();
    }
}
