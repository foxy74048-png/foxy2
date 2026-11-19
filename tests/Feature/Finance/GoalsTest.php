<?php

namespace Tests\Feature\Finance;

use App\Finance\Accounts\Account;
use App\Finance\Goals\Goal;
use App\Finance\Goals\GoalEntry;
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
 * Foxy Finanças, fatia 6b: metas de guardar dinheiro — quanto falta, quanto guardar por mês até o prazo, se está
 * no ritmo, "guardei"/"tirei" (com transferência de verdade quando a meta tem conta) e a reserva de emergência.
 */
class GoalsTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private Wallet $wallet;

    private Account $bank;

    private Account $savings;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-19 10:00:00');
        $this->ana = User::factory()->create();
        $this->wallet = app(OpenWallet::class)->open($this->ana, 'Pessoal');
        [$this->bank, $this->savings] = $this->in(fn () => [
            Account::create(['kind' => 'corrente', 'name' => 'Nubank', 'opening_balance_cents' => 500000]),
            Account::create(['kind' => 'poupanca', 'name' => 'Poupança']),
        ]);
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

    private function goal(): Goal
    {
        return $this->in(fn () => Goal::firstOrFail());
    }

    public function test_meta_com_prazo_diz_quanto_guardar_por_mes(): void
    {
        // Viagem de R$ 6.000 em julho/2027, já com R$ 600: faltam 5.400 em 9 meses (nov a jul) = 600 por mês.
        $this->actingAs($this->ana)->post($this->url('/app/metas'), ['name' => 'Viagem de julho', 'target' => '6.000', 'deadline' => '2027-07-31', 'start' => '600', 'account_id' => $this->savings->id])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'R$ 600,00 por mês'));

        $p = $this->in(fn () => $this->goal()->progress());
        $this->assertSame([60000, 540000, 9, 60000], [$p['saved'], $p['missing'], $p['months_left'], $p['per_month']]);
        $this->assertEqualsWithDelta(0.1, $p['pct'], 0.001);
        $this->assertTrue($p['on_track'], 'Criada hoje com 10% já guardado: está no ritmo.');

        // Seis meses depois, sem guardar mais nada: atrasada.
        Carbon::setTestNow('2027-05-19 10:00:00');
        $p = $this->in(fn () => $this->goal()->progress());
        $this->assertFalse($p['on_track']);
        // Faltam maio, junho e julho: 5.400 ÷ 3 = 1.800 por mês.
        $this->assertSame([3, 180000], [$p['months_left'], $p['per_month']]);
    }

    public function test_guardei_com_conta_vira_transferencia_e_tirei_tambem(): void
    {
        $this->in(fn () => Goal::create(['name' => 'Reserva de emergência', 'target_cents' => 300000, 'account_id' => $this->savings->id]));
        $goal = $this->goal();

        $this->actingAs($this->ana)->post($this->url("/app/metas/{$goal->id}/entradas"), ['direction' => 'guardei', 'amount' => '1.000', 'date' => '2026-11-19', 'other_account_id' => $this->bank->id])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Falta R$ 2.000,00'));

        $t = $this->in(fn () => Transaction::firstOrFail());
        $this->assertSame(['transferencia', $this->bank->id, $this->savings->id, 100000], [$t->kind, $t->account_id, $t->to_account_id, $t->amount_cents]);
        $balances = $this->in(fn () => app(TransactionService::class)->balances());
        $this->assertSame([400000, 100000], [$balances[$this->bank->id], $balances[$this->savings->id]]);

        // Só anotar (sem conta de origem): soma na meta, sem transferência.
        $this->actingAs($this->ana)->post($this->url("/app/metas/{$goal->id}/entradas"), ['direction' => 'guardei', 'amount' => '500', 'date' => '2026-11-19']);
        $this->assertSame(1, $this->in(fn () => Transaction::count()));
        $this->assertSame(150000, $this->in(fn () => $goal->fresh()->savedCents()));

        // Tirei: não pode tirar mais do que tem; tirando para a conta, vira transferência de volta.
        $this->actingAs($this->ana)->post($this->url("/app/metas/{$goal->id}/entradas"), ['direction' => 'tirei', 'amount' => '2.000', 'date' => '2026-11-19'])->assertSessionHasErrors('amount');
        $this->actingAs($this->ana)->post($this->url("/app/metas/{$goal->id}/entradas"), ['direction' => 'tirei', 'amount' => '200', 'date' => '2026-11-19', 'other_account_id' => $this->bank->id]);
        $back = $this->in(fn () => Transaction::latest('created_at')->orderByDesc('id')->get()->firstWhere('account_id', $this->savings->id));
        $this->assertSame($this->bank->id, $back->to_account_id);
        $this->assertSame(130000, $this->in(fn () => $goal->fresh()->savedCents()));

        // Apagar a entrada tira a transferência dos lançamentos.
        $entry = $this->in(fn () => GoalEntry::where('amount_cents', 100000)->firstOrFail());
        $this->actingAs($this->ana)->post($this->url("/app/metas/entradas/{$entry->id}/apagar"));
        $this->assertNull($this->in(fn () => Transaction::find($t->id)));
        $this->assertSame(30000, $this->in(fn () => $goal->fresh()->savedCents()));
    }

    public function test_alcancou_a_meta(): void
    {
        $this->in(fn () => Goal::create(['name' => 'Curso', 'target_cents' => 100000, 'start_cents' => 80000]));
        $goal = $this->goal();

        $this->actingAs($this->ana)->post($this->url("/app/metas/{$goal->id}/entradas"), ['direction' => 'guardei', 'amount' => '200', 'date' => '2026-11-19'])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'alcançada'));
        $this->assertNotNull($this->in(fn () => $goal->fresh()->achieved_at));
        $this->actingAs($this->ana)->get($this->url('/app/metas'))->assertSee('Alcançada');

        // Tirou de novo: volta a ser meta em andamento.
        $this->actingAs($this->ana)->post($this->url("/app/metas/{$goal->id}/entradas"), ['direction' => 'tirei', 'amount' => '100', 'date' => '2026-11-19']);
        $this->assertNull($this->in(fn () => $goal->fresh()->achieved_at));
    }

    public function test_atalho_da_reserva_usa_6_vezes_o_gasto_medio(): void
    {
        // Gasto médio de R$ 2.000 nos 3 meses antes → reserva de R$ 12.000.
        $this->in(function () {
            foreach (['2026-08-10', '2026-09-10', '2026-10-10'] as $d) {
                app(TransactionService::class)->create(['kind' => 'gasto', 'amount_cents' => 200000, 'date' => $d, 'description' => 'Contas', 'account_id' => $this->bank->id]);
            }
        });

        $this->actingAs($this->ana)->get($this->url('/app/metas'))->assertSee('Comece pela reserva de emergência')->assertSee('R$ 12.000,00');
        $this->actingAs($this->ana)->post($this->url('/app/metas'), ['name' => 'Reserva de emergência', 'target' => '12.000,00', 'account_id' => $this->savings->id]);
        $this->actingAs($this->ana)->get($this->url('/app/metas'))->assertDontSee('Comece pela reserva de emergência');
        $this->actingAs($this->ana)->get($this->url('/app'))->assertSee('Metas')->assertSee('Reserva de emergência');
    }

    public function test_validacoes_exportacao_e_acesso(): void
    {
        $this->actingAs($this->ana)->post($this->url('/app/metas'), ['name' => 'X', 'target' => '0'])->assertSessionHasErrors('target');
        $this->actingAs($this->ana)->post($this->url('/app/metas'), ['name' => 'X', 'target' => '10', 'deadline' => '2020-01-01'])->assertSessionHasErrors('deadline');
        $card = $this->in(fn () => Account::create(['kind' => 'cartao', 'name' => 'Cartão', 'closing_day' => 1, 'due_day' => 8]));
        $this->actingAs($this->ana)->post($this->url('/app/metas'), ['name' => 'X', 'target' => '10', 'account_id' => $card->id])->assertSessionHasErrors('account_id');

        $this->actingAs($this->ana)->post($this->url('/app/metas'), ['name' => 'Carro', 'target' => '30.000', 'deadline' => '2028-01-01']);
        $goal = $this->goal();
        $this->assertStringContainsString('Carro', $this->actingAs($this->ana)->get($this->url('/app/metas?exportar=planilha'))->streamedContent());

        $viewer = User::factory()->create();
        Membership::create(['organization_id' => $this->wallet->organization_id, 'user_id' => $viewer->id, 'role' => 'viewer']);
        $this->actingAs($viewer)->get($this->url('/app/metas'))->assertOk()->assertDontSee('Criar meta');
        $this->actingAs($viewer)->post($this->url("/app/metas/{$goal->id}/entradas"), ['direction' => 'guardei', 'amount' => '1', 'date' => '2026-11-19'])->assertForbidden();

        $bia = User::factory()->create();
        app(OpenWallet::class)->open($bia, 'Da Bia');
        $this->actingAs($bia)->post($this->url("/app/metas/{$goal->id}/apagar"))->assertNotFound();
        $this->assertNotNull($goal->fresh());
    }
}
