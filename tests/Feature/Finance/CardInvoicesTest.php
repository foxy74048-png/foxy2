<?php

namespace Tests\Feature\Finance;

use App\Finance\Accounts\Account;
use App\Finance\Accounts\CardInvoices;
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
 * Foxy Finanças, fatia 5 (docs/12, seção 11): fatura atual e futuras, situação, pagar a fatura (transferência
 * ligada a ela), limite disponível contando as parcelas, comprometido nos próximos meses e aviso no Início.
 */
class CardInvoicesTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private Wallet $wallet;

    private Account $bank;

    private Account $card;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-18 10:00:00');
        $this->ana = User::factory()->create();
        $this->wallet = app(OpenWallet::class)->open($this->ana, 'Pessoal');
        [$this->bank, $this->card] = $this->in(fn () => [
            Account::create(['kind' => 'corrente', 'name' => 'Nubank', 'opening_balance_cents' => 500000]),
            // Fecha dia 10, vence dia 17.
            Account::create(['kind' => 'cartao', 'name' => 'Cartão Inter', 'closing_day' => 10, 'due_day' => 17, 'limit_cents' => 200000]),
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

    private function spend(string $date, int $cents, string $description, string $kind = 'gasto', array $extra = []): void
    {
        $this->in(fn () => app(TransactionService::class)->create(['kind' => $kind, 'amount_cents' => $cents, 'date' => $date, 'description' => $description, 'account_id' => $this->card->id] + $extra));
    }

    private function invoice(string $month): array
    {
        return $this->in(fn () => app(CardInvoices::class)->invoice($this->card, $month));
    }

    /** Novembro (vencida), dezembro (aberta) e parcelas até fevereiro. */
    private function scenario(): void
    {
        $this->spend('2026-10-20', 30000, 'Restaurante');      // fatura de novembro
        $this->spend('2026-11-05', 20000, 'Mercado');           // novembro
        $this->spend('2026-11-06', 5000, 'Estorno loja', 'recebimento'); // novembro, abate
        $this->spend('2026-11-12', 10000, 'Farmácia');          // dezembro (depois do fechamento)
        $this->spend('2026-11-15', 90000, 'Celular', 'gasto', ['mode' => 'parcelas', 'count' => 3]); // dez, jan, fev
    }

    public function test_datas_de_fechamento_e_vencimento(): void
    {
        $this->assertSame(['2026-11-10', '2026-11-17'], [$this->card->closingDate('2026-11')->toDateString(), $this->card->dueDate('2026-11')->toDateString()]);
        $this->assertSame('2026-11', $this->card->paidInvoiceFor('2026-11-15'));

        // Fecha dia 25 e vence dia 5: a fatura de dezembro fecha em 25/11.
        $other = new Account(['kind' => 'cartao', 'closing_day' => 25, 'due_day' => 5]);
        $this->assertSame(['2026-11-25', '2026-12-05'], [$other->closingDate('2026-12')->toDateString(), $other->dueDate('2026-12')->toDateString()]);
        $this->assertSame('2026-12', $other->paidInvoiceFor('2026-12-03'));
    }

    public function test_fatura_soma_compras_menos_estornos_e_diz_a_situacao(): void
    {
        $this->scenario();

        $nov = $this->invoice('2026-11');
        $this->assertSame([45000, 0, 45000, 'atrasada'], [$nov['charges'], $nov['payments'], $nov['remaining'], $nov['status']]);
        $dec = $this->invoice('2026-12');
        $this->assertSame([40000, 'aberta'], [$dec['charges'], $dec['status']]);
        $this->assertSame([30000, 'futura'], [$this->invoice('2027-01')['charges'], $this->invoice('2027-01')['status']]);

        $invoices = app(CardInvoices::class);
        $this->assertSame(['2027-01' => 30000, '2027-02' => 30000], $this->in(fn () => $invoices->committed($this->card)));
        $this->assertSame(145000, $this->in(fn () => $invoices->outstanding($this->card)));

        $this->actingAs($this->ana)->get($this->url('/app/cartoes'))->assertOk()
            ->assertSee('Limite disponível')->assertSee('R$ 550,00')->assertSee('Vencida')->assertSee('R$ 600,00');
    }

    public function test_pagar_a_fatura_registra_a_transferencia_ligada_a_ela(): void
    {
        $this->scenario();

        // Sem pedir fatura, abre a que está para pagar.
        $this->actingAs($this->ana)->get($this->url("/app/cartoes/{$this->card->id}"))->assertOk()->assertSee('Fatura de 11/2026')->assertSee('Pagar esta fatura')->assertSee('Estorno loja');

        $this->actingAs($this->ana)->post($this->url("/app/cartoes/{$this->card->id}/pagar"), ['fatura' => '2026-11', 'from_account_id' => $this->bank->id, 'amount' => '200,00', 'date' => '2026-11-18'])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Ainda falta R$ 250,00'));
        $this->assertSame('atrasada', $this->invoice('2026-11')['status'], 'Vencida e ainda falta: continua vencida.');

        $this->actingAs($this->ana)->post($this->url("/app/cartoes/{$this->card->id}/pagar"), ['fatura' => '2026-11', 'from_account_id' => $this->bank->id, 'amount' => '250', 'date' => '2026-11-18'])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Fatura paga'));
        $this->assertSame('paga', $this->invoice('2026-11')['status']);

        $payment = $this->in(fn () => Transaction::where('kind', 'transferencia')->firstOrFail());
        $this->assertSame([$this->bank->id, $this->card->id, '2026-11'], [$payment->account_id, $payment->to_account_id, $payment->invoice_month]);
        // Sai do saldo da conta; não é gasto.
        $this->assertSame(455000, $this->in(fn () => app(TransactionService::class)->balances())[$this->bank->id]);
        $this->assertSame(100000, $this->in(fn () => app(CardInvoices::class)->outstanding($this->card)));
    }

    public function test_pagamento_lancado_em_lancamentos_vai_para_a_ultima_fatura_que_fechou(): void
    {
        Carbon::setTestNow('2026-11-12 10:00:00');
        $this->scenario();
        $this->assertSame('fechada', $this->invoice('2026-11')['status']);

        $this->actingAs($this->ana)->post($this->url('/app/lancamentos'), ['kind' => 'transferencia', 'amount' => '100', 'description' => 'Fatura', 'account_id' => $this->bank->id, 'to_account_id' => $this->card->id, 'date' => '2026-11-12']);
        $this->assertSame([10000, 'parcial'], [$this->invoice('2026-11')['payments'], $this->invoice('2026-11')['status']]);

        // Editar a data do pagamento não muda a fatura que ele paga.
        $payment = $this->in(fn () => Transaction::where('kind', 'transferencia')->firstOrFail());
        $this->actingAs($this->ana)->post($this->url("/app/lancamentos/{$payment->id}"), ['amount' => '100', 'description' => 'Fatura', 'account_id' => $this->bank->id, 'to_account_id' => $this->card->id, 'date' => '2026-11-20']);
        $this->assertSame('2026-11', $payment->fresh()->invoice_month);
    }

    public function test_assinatura_no_cartao_nao_pesa_no_limite_e_entra_sozinha_quando_chega_a_data(): void
    {
        $this->spend('2026-11-02', 5590, 'Netflix', 'gasto', ['mode' => 'mensal', 'count' => 12]);
        $rows = $this->in(fn () => Transaction::orderBy('date')->get());
        $this->assertSame([Transaction::DONE, Transaction::PLANNED], [$rows[0]->status, $rows[1]->status]);

        // Só a deste mês conta: o banco não reserva o limite das assinaturas futuras.
        $invoices = app(CardInvoices::class);
        $this->assertSame(5590, $this->in(fn () => $invoices->outstanding($this->card)));
        $this->assertSame([], $this->in(fn () => $invoices->committed($this->card)));

        // Chegou dezembro: a cobrança entra sozinha (sem "Paguei"), pela rotina diária e ao abrir as faturas.
        Carbon::setTestNow('2026-12-02 08:00:00');
        $this->artisan('financas:cartoes')->expectsOutputToContain('1 cobrança(s)')->assertSuccessful();
        $this->assertSame(Transaction::DONE, $rows[1]->fresh()->status);
        $this->assertSame(Transaction::PLANNED, $rows[2]->fresh()->status);

        Carbon::setTestNow('2027-01-03 08:00:00');
        $this->actingAs($this->ana)->get($this->url('/app/cartoes'))->assertOk();
        $this->assertSame(Transaction::DONE, $rows[2]->fresh()->status);
    }

    public function test_inicio_avisa_a_fatura_para_pagar(): void
    {
        $this->scenario();

        $this->actingAs($this->ana)->get($this->url('/app'))->assertOk()
            ->assertSee('Fatura do <strong>Cartão Inter</strong> de 11/2026', false)->assertSee('venceu em 17/11');
        $this->actingAs($this->ana)->get($this->url('/app/contas'))->assertSee('R$ 400,00');
    }

    public function test_exporta_a_fatura_e_protege_o_acesso(): void
    {
        $this->scenario();
        $csv = $this->actingAs($this->ana)->get($this->url("/app/cartoes/{$this->card->id}?fatura=2026-11&exportar=planilha"))->streamedContent();
        $this->assertStringContainsString('Restaurante', $csv);
        $this->assertStringContainsString('-50,00', $csv);
        $this->assertStringNotContainsString('Farmácia', $csv);
        $this->actingAs($this->ana)->get($this->url("/app/cartoes/{$this->card->id}?fatura=2026-11&exportar=pdf"))->assertHeader('Content-Type', 'application/pdf');

        // Conta que não é cartão, cartão de outra carteira, e quem só vê não paga.
        $this->actingAs($this->ana)->get($this->url("/app/cartoes/{$this->bank->id}"))->assertNotFound();
        $bia = User::factory()->create();
        app(OpenWallet::class)->open($bia, 'Da Bia');
        $this->actingAs($bia)->get($this->url("/app/cartoes/{$this->card->id}"))->assertNotFound();

        $viewer = User::factory()->create();
        Membership::create(['organization_id' => $this->wallet->organization_id, 'user_id' => $viewer->id, 'role' => 'viewer']);
        $this->actingAs($viewer)->get($this->url("/app/cartoes/{$this->card->id}?fatura=2026-11"))->assertOk()->assertDontSee('Pagar esta fatura');
        $this->actingAs($viewer)->post($this->url("/app/cartoes/{$this->card->id}/pagar"), ['fatura' => '2026-11', 'from_account_id' => $this->bank->id, 'amount' => '10', 'date' => '2026-11-18'])->assertForbidden();
    }
}
