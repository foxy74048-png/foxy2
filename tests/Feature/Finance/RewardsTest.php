<?php

namespace Tests\Feature\Finance;

use App\Finance\Accounts\Account;
use App\Finance\Budgets\Budget;
use App\Finance\Categories\Category;
use App\Finance\Goals\Goal;
use App\Finance\Goals\GoalEntry;
use App\Finance\Rewards\Challenge;
use App\Finance\Rewards\Reward;
use App\Finance\Rewards\Rewards;
use App\Finance\Transactions\Transaction;
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
 * Conquistas (pedido em 2026-11-24): pontos por carteira pelo que melhora o dinheiro (mês no azul, orçamento,
 * meta no ritmo, fatura em dia, extrato revisado, desafio vencido), níveis da raposa, conquistas e desafios
 * sugeridos com os números da carteira. Recalcular nunca duplica.
 */
class RewardsTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private Wallet $wallet;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-19 10:00:00');
        $this->ana = User::factory()->create();
        $this->wallet = app(OpenWallet::class)->open($this->ana, 'Pessoal');
        $this->bank = $this->in(fn () => Account::create(['kind' => 'corrente', 'name' => 'Nubank', 'opening_balance_cents' => 100000]));
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

    private function tx(string $kind, int $cents, string $date, ?string $categoryKey = null, ?Account $account = null, array $extra = []): Transaction
    {
        return $this->in(fn () => app(TransactionService::class)->create([
            'kind' => $kind, 'amount_cents' => $cents, 'date' => $date, 'description' => ucfirst($kind),
            'account_id' => ($account ?? $this->bank)->id, 'category_id' => $categoryKey ? Category::where('system_key', $categoryKey)->value('id') : null,
        ] + $extra)->first());
    }

    private function keys(): array
    {
        return $this->in(fn () => Reward::query()->where('kind', '!=', Reward::MARKER)->orderBy('key')->pluck('points', 'key')->all());
    }

    public function test_mes_que_fechou_pontua_azul_orcamento_e_desafio_e_nao_duplica(): void
    {
        $this->tx('recebimento', 500000, '2026-10-05', 'salario');
        $this->tx('gasto', 60000, '2026-10-08', 'mercado');
        $this->tx('gasto', 40000, '2026-10-12', 'delivery');
        $this->in(function () {
            Budget::create(['category_id' => Category::where('system_key', 'mercado')->value('id'), 'amount_cents' => 80000]);
            Budget::create(['category_id' => Category::where('system_key', 'delivery')->value('id'), 'amount_cents' => 30000]);
            $comerFora = Category::where('system_key', 'delivery')->value('parent_id');
            Challenge::create(['month' => '2026-10', 'kind' => Challenge::CATEGORY, 'category_id' => $comerFora, 'target_cents' => 50000, 'label' => 'Comer fora até R$ 500']);
            Challenge::create(['month' => '2026-10', 'kind' => Challenge::SMALL, 'target_count' => 0, 'label' => 'Nenhum gastinho']);
        });
        $this->tx('gasto', 1500, '2026-10-20', 'delivery');

        $this->in(fn () => app(Rewards::class)->catchUp());
        $this->in(fn () => app(Rewards::class)->catchUp());

        $keys = $this->keys();
        $this->assertSame(100, $keys['azul:2026-10']);
        $mercado = $this->in(fn () => Category::where('system_key', 'mercado')->value('id'));
        $this->assertSame(30, $keys["orcamento:{$mercado}:2026-10"]);
        $this->assertCount(1, array_filter(array_keys($keys), fn ($k) => str_starts_with($k, 'orcamento:')), 'delivery passou do limite');

        [$won, $lost] = $this->in(fn () => [Challenge::where('kind', Challenge::CATEGORY)->first(), Challenge::where('kind', Challenge::SMALL)->first()]);
        $this->assertSame(Challenge::WON, $won->status);
        $this->assertSame(41500, $won->result);
        $this->assertSame(Challenge::LOST, $lost->status);
        $this->assertSame(80, $keys['desafio:'.$won->id]);
        $this->assertArrayNotHasKey('desafio:'.$lost->id, $keys);
        $this->assertSame(1, $this->in(fn () => Reward::where('key', 'avaliado:2026-10')->count()));
    }

    public function test_fatura_paga_ate_o_vencimento_pontua(): void
    {
        $card = $this->in(fn () => Account::create(['kind' => 'cartao', 'name' => 'Cartão Inter', 'closing_day' => 5, 'due_day' => 12, 'limit_cents' => 500000]));
        $charge = $this->tx('gasto', 20000, '2026-09-20', 'mercado', $card);
        $month = $charge->invoice_month;
        $due = $this->in(fn () => $card->dueDate($month));
        $this->tx('transferencia', 20000, $due->copy()->subDay()->toDateString(), null, $this->bank, ['to_account_id' => $card->id, 'invoice_month' => $month]);

        $this->in(fn () => app(Rewards::class)->evaluateMonth(Carbon::createFromFormat('Y-m-d', $month.'-01')));

        $this->assertSame(30, $this->keys()["fatura:{$card->id}:{$month}"]);
    }

    public function test_conquistas_meta_reserva_e_nivel(): void
    {
        $this->in(function () {
            $poupanca = Account::create(['kind' => 'poupanca', 'name' => 'Poupança', 'opening_balance_cents' => 900000]);
            $goal = Goal::create(['name' => 'Viagem', 'target_cents' => 100000, 'start_cents' => 0]);
            GoalEntry::create(['goal_id' => $goal->id, 'amount_cents' => 100000, 'date' => '2026-11-10']);
        });
        // Gasto médio de R$ 3.000 nos 3 meses: R$ 9.000 guardados = 3 meses de reserva.
        foreach (['2026-08-10', '2026-09-10', '2026-10-10'] as $d) {
            $this->tx('gasto', 300000, $d, 'aluguel');
        }

        $this->in(fn () => app(Rewards::class)->badges());

        $keys = $this->keys();
        $this->assertSame(20, $keys['badge:primeira_meta']);
        $this->assertSame(50, $keys['badge:reserva_1']);
        $this->assertSame(100, $keys['badge:reserva_3']);
        $this->assertArrayNotHasKey('badge:reserva_6', $keys);
        $goalId = $this->in(fn () => Goal::value('id'));
        $this->assertSame(100, $keys['badge:meta_batida:'.$goalId]);

        $level = $this->in(fn () => app(Rewards::class)->level());
        // 20 + 50 + 100 (reservas de 1 e 3 meses) + 100 (meta batida) = 270 pontos.
        $this->assertSame(['Raposinha', 270, 'Raposa esperta'], [$level['name'], $level['points'], $level['next']]);
        $this->assertSame('Raposa esperta', app(Rewards::class)->level(700)['name']);
    }

    public function test_tela_sugere_desafios_com_os_numeros_e_aceita(): void
    {
        foreach (['2026-08-10', '2026-09-10', '2026-10-10'] as $d) {
            $this->tx('gasto', 40000, $d, 'delivery');
        }
        $this->in(fn () => Goal::create(['name' => 'Carro', 'target_cents' => 1200000, 'deadline' => '2027-10-31', 'start_cents' => 0]));

        $page = $this->actingAs($this->ana)->get($this->url('/app/conquistas'))->assertOk()
            ->assertSee('Desafios de novembro')->assertSee('Aceitar desafio')->assertSee('Filhote');
        $suggestions = $this->in(fn () => app(Rewards::class)->suggestions());
        $this->assertSame(Challenge::CATEGORY, $suggestions[0]['kind']);
        $this->assertSame(32000, $suggestions[0]['target_cents']);
        $this->assertTrue($suggestions->contains(fn ($s) => $s['kind'] === Challenge::GOAL && $s['target_cents'] === 100000));
        $page->assertSee('Gastar até R$ 320,00 com Comer fora');

        $this->actingAs($this->ana)->post($this->url('/app/conquistas/desafios'), ['suggestion' => 0])->assertSessionHas('status');
        $challenge = $this->in(fn () => Challenge::first());
        $this->assertSame([Challenge::ACTIVE, '2026-11', 32000, $this->ana->id], [$challenge->status, $challenge->month, $challenge->target_cents, (int) $challenge->accepted_by]);

        $this->tx('gasto', 10000, '2026-11-15', 'delivery');
        $this->actingAs($this->ana)->get($this->url('/app/conquistas'))->assertSee('ainda dá R$ 220,00');
        $this->actingAs($this->ana)->get($this->url('/app'))->assertOk()->assertSee('Conquistas')->assertSee('Gastar até R$ 320,00 com Comer fora');

        $this->actingAs($this->ana)->post($this->url('/app/conquistas/desafios/'.$challenge->id.'/desistir'))->assertSessionHas('status');
        $this->assertSame(0, $this->in(fn () => Challenge::count()));
    }

    public function test_quem_so_ve_nao_aceita_e_outra_carteira_nao_ve_o_desafio(): void
    {
        $viewer = User::factory()->create();
        Membership::create(['organization_id' => $this->wallet->organization_id, 'user_id' => $viewer->id, 'role' => 'viewer']);
        $challenge = $this->in(fn () => Challenge::create(['month' => '2026-11', 'kind' => Challenge::SMALL, 'target_count' => 3, 'label' => 'Poucos gastinhos']));

        $this->actingAs($viewer)->get($this->url('/app/conquistas'))->assertOk()->assertDontSee('Aceitar desafio');
        $this->actingAs($viewer)->post($this->url('/app/conquistas/desafios'), ['suggestion' => 0])->assertForbidden();

        $bia = User::factory()->create();
        app(OpenWallet::class)->open($bia, 'Pessoal');
        $this->actingAs($bia)->post($this->url('/app/conquistas/desafios/'.$challenge->id.'/desistir'))->assertNotFound();
        $this->assertSame(1, $this->in(fn () => Challenge::count()));
    }

    public function test_rotina_diaria_avalia_todas_as_carteiras(): void
    {
        $this->tx('recebimento', 100000, '2026-10-05', 'salario');
        $this->artisan('financas:conquistas')->assertSuccessful();
        $this->artisan('financas:conquistas')->assertSuccessful();
        $this->assertSame(100, $this->keys()['azul:2026-10']);
    }
    public function test_ranking_mostra_quem_mais_ajudou_na_carteira_compartilhada(): void
    {
        $bruno = User::factory()->create(['name' => 'Bruno']);
        Membership::create(['organization_id' => $this->wallet->organization_id, 'user_id' => $bruno->id, 'role' => 'member']);
        $goal = $this->in(fn () => Goal::create(['name' => 'Viagem', 'target_cents' => 500000]));
        $this->in(fn () => GoalEntry::create(['goal_id' => $goal->id, 'amount_cents' => 20000, 'date' => '2026-11-05', 'created_by' => (string) $this->ana->id]));
        $this->in(fn () => GoalEntry::create(['goal_id' => $goal->id, 'amount_cents' => 50000, 'date' => '2026-11-06', 'created_by' => (string) $bruno->id]));
        $this->tx('gasto', 3000, '2026-11-07', 'mercado');

        $ranking = $this->in(fn () => app(Rewards::class)->ranking($this->wallet->organization_id));
        $this->assertSame(['Bruno', 50000, 0], [$ranking[0]['name'], $ranking[0]['saved'], $ranking[0]['entries']]);
        $this->assertSame([20000, 1], [$ranking[1]['saved'], $ranking[1]['entries']]);
        $this->actingAs($this->ana)->get($this->url('/app/conquistas'))->assertSee('Quem mais ajudou em novembro')->assertSee('Bruno');

        // Sozinha na carteira: sem ranking.
        $this->assertTrue(app(Rewards::class)->ranking(app(OpenWallet::class)->open(User::factory()->create(), 'Só eu')->organization_id)->isEmpty());
    }

    public function test_no_negocio_o_mes_no_azul_e_o_lucro(): void
    {
        $business = app(OpenWallet::class)->open($this->ana, 'Ateliê', Wallet::BUSINESS, true);
        $run = fn (callable $fn) => app(ContextStore::class)->run(ExecutionContext::forUser($business->organization, $this->ana->id), $fn);
        $run(function () {
            $pj = Account::create(['kind' => 'corrente', 'name' => 'Conta PJ']);
            app(TransactionService::class)->create(['kind' => 'recebimento', 'amount_cents' => 900000, 'date' => '2026-10-10', 'description' => 'Vendas', 'account_id' => $pj->id]);
            app(Rewards::class)->catchUp();
        });

        $this->assertStringContainsString('com lucro (R$ 9.000,00)', $run(fn () => Reward::where('key', 'azul:2026-10')->value('label')));
    }

    public function test_lembrete_dos_desafios_pelo_whatsapp_so_para_quem_ligou(): void
    {
        config(['foxy.messaging.whatsapp' => ['api_url' => 'https://evo.test', 'api_key' => 'k', 'instance' => 'foxy', 'webhook_token' => 'segredo']]);
        \Illuminate\Support\Facades\Http::fake(['evo.test/*' => \Illuminate\Support\Facades\Http::response(['key' => ['id' => 'x']], 201)]);
        $link = \App\Finance\WhatsApp\PhoneLink::create(['user_id' => $this->ana->id, 'phone' => '5511987654321', 'verified_at' => now()]);
        $this->in(fn () => Challenge::create(['month' => '2026-11', 'kind' => Challenge::SMALL, 'target_count' => 5, 'label' => 'No máximo 5 gastinhos']));

        $this->artisan('financas:desafios-whatsapp')->assertSuccessful();
        \Illuminate\Support\Facades\Http::assertNothingSent();

        $this->actingAs($this->ana)->get($this->url('/app/perfil'))->assertSee('Ligar lembrete');
        $this->actingAs($this->ana)->post($this->url('/app/perfil/whatsapp/lembrete'), ['on' => '1'])->assertSessionHas('status');
        $this->assertTrue($link->fresh()->challenge_reminders);

        $this->artisan('financas:desafios-whatsapp')->assertSuccessful();
        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => str_contains($r->url(), '/message/sendText/foxy_financas') && $r['number'] === '5511987654321'
            && str_contains($r['text'], 'No máximo 5 gastinhos') && str_contains($r['text'], '*Pessoal*'));
    }
}
