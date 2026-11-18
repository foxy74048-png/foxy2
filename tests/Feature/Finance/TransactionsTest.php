<?php

namespace Tests\Feature\Finance;

use App\Finance\Accounts\Account;
use App\Finance\Categories\Category;
use App\Finance\Transactions\Transaction;
use App\Finance\Wallets\OpenWallet;
use App\Finance\Wallets\Wallet;
use App\Models\User;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Memberships\Membership;
use App\Platform\Products\ProductAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Foxy Finanças, fatia 2 (docs/12, seção 11): contas e cartões, categorias prontas, lançar (uma vez, parcelado,
 * todo mês, transferência), previsto × feito, saldo, editar e apagar a série, filtros e exportação.
 */
class TransactionsTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-18 10:00:00');
        $this->ana = User::factory()->create(['email' => 'ana@casa.test']);
        $this->wallet = app(OpenWallet::class)->open($this->ana, 'Pessoal');
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

    /** Roda dentro da carteira (para criar dados direto nos testes). */
    private function inWallet(callable $fn, ?Wallet $wallet = null)
    {
        $wallet ??= $this->wallet;

        return app(ContextStore::class)->run(ExecutionContext::forUser($wallet->organization, $this->ana->id), $fn);
    }

    private function account(string $name = 'Nubank', string $kind = 'corrente', array $extra = []): Account
    {
        return $this->inWallet(fn () => Account::create(['kind' => $kind, 'name' => $name] + $extra));
    }

    private function category(string $key): Category
    {
        return $this->inWallet(fn () => Category::where('system_key', $key)->firstOrFail());
    }

    private function asAna(string $path, array $data = [])
    {
        return $this->actingAs($this->ana)->call('POST', $this->url($path), $data);
    }

    private function rows()
    {
        return $this->inWallet(fn () => Transaction::orderBy('date')->get());
    }

    public function test_carteira_ja_nasce_com_as_categorias_prontas(): void
    {
        $this->inWallet(function () {
            $this->assertTrue(Category::where('name', 'Mercado')->where('kind', 'gasto')->exists());
            $this->assertSame('Moradia', Category::where('system_key', 'aluguel')->first()->parent->name);
            $this->assertTrue(Category::where('name', 'Salário')->where('kind', 'recebimento')->exists());
        });

        $business = app(OpenWallet::class)->open($this->ana, 'Ateliê', Wallet::BUSINESS);
        $this->inWallet(function () {
            $this->assertTrue(Category::where('system_key', 'das')->exists());
            $this->assertFalse(Category::where('name', 'Mercado')->exists());
        }, $business);
    }

    public function test_primeiro_passo_e_cadastrar_a_conta_e_voltar_para_lancar(): void
    {
        $this->actingAs($this->ana)->get($this->url('/app/lancamentos'))->assertOk()->assertSee('onde o seu dinheiro fica?');

        $this->asAna('/app/contas', ['kind' => 'corrente', 'name' => 'Nubank', 'opening_balance' => '1.500,00', 'back' => 'lancamentos'])
            ->assertRedirect($this->url('/app/lancamentos'));
        $this->assertSame(150000, $this->inWallet(fn () => Account::first()->opening_balance_cents));
        $this->get($this->url('/app/lancamentos'))->assertOk()->assertSee('Lançar');
    }

    public function test_cartao_precisa_de_fechamento_e_vencimento(): void
    {
        $this->asAna('/app/contas', ['kind' => 'cartao', 'name' => 'Cartão Inter'])->assertSessionHasErrors('closing_day');
        $this->asAna('/app/contas', ['kind' => 'cartao', 'name' => 'Cartão Inter', 'closing_day' => 10, 'due_day' => 17, 'limit' => '3.000', 'opening_balance' => '999'])->assertSessionHasNoErrors();

        $card = $this->inWallet(fn () => Account::first());
        $this->assertSame(0, $card->opening_balance_cents);
        $this->assertSame(300000, $card->limit_cents);
    }

    public function test_fatura_em_que_cai_a_compra(): void
    {
        $card = new Account(['kind' => 'cartao', 'closing_day' => 10, 'due_day' => 17]);
        $this->assertSame('2026-11', $card->invoiceMonthFor('2026-11-09'));
        $this->assertSame('2026-12', $card->invoiceMonthFor('2026-11-10'));

        // Fecha dia 25 e vence dia 5: a fatura vence no mês seguinte ao fechamento.
        $card = new Account(['kind' => 'cartao', 'closing_day' => 25, 'due_day' => 5]);
        $this->assertSame('2026-12', $card->invoiceMonthFor('2026-11-20'));
        $this->assertSame('2027-01', $card->invoiceMonthFor('2026-11-26'));

        // Fechamento dia 31 em fevereiro: fecha no último dia.
        $card = new Account(['kind' => 'cartao', 'closing_day' => 31, 'due_day' => 8]);
        $this->assertSame('2026-04', $card->invoiceMonthFor('2026-02-28'));

        $this->assertNull((new Account(['kind' => 'corrente']))->invoiceMonthFor('2026-11-01'));
    }

    public function test_lancar_gasto_em_tres_toques_e_ver_no_mes(): void
    {
        $nubank = $this->account();
        $mercado = $this->category('mercado');

        $this->asAna('/app/lancamentos', ['kind' => 'gasto', 'amount' => '42,90', 'description' => 'Mercado do bairro', 'account_id' => $nubank->id, 'category_id' => $mercado->id, 'date' => '2026-11-18'])
            ->assertRedirect($this->url('/app/lancamentos?mes=2026-11'))->assertSessionHas('status');

        $t = $this->rows()->first();
        $this->assertSame(4290, $t->amount_cents);
        $this->assertSame(Transaction::DONE, $t->status);
        $this->assertSame((string) $this->ana->id, $t->created_by);

        $this->get($this->url('/app/lancamentos'))->assertOk()->assertSee('Mercado do bairro')->assertSee('R$ 42,90');
        // A conta fica escolhida para o próximo lançamento.
        $this->assertSame($nubank->id, session('finance.last_account'));
    }

    public function test_data_futura_fica_prevista_ate_confirmar_e_nao_entra_no_saldo(): void
    {
        $nubank = $this->account('Nubank', 'corrente', ['opening_balance_cents' => 100000]);

        $this->asAna('/app/lancamentos', ['kind' => 'gasto', 'amount' => '300', 'description' => 'Boleto da escola', 'account_id' => $nubank->id, 'date' => '2026-11-25']);
        $t = $this->rows()->first();
        $this->assertSame(Transaction::PLANNED, $t->status);

        $this->actingAs($this->ana)->get($this->url('/app/contas'))->assertSee('R$ 1.000,00');
        $this->get($this->url('/app'))->assertSee('Boleto da escola');

        $this->asAna("/app/lancamentos/{$t->id}/confirmar", ['date' => '2026-11-18'])->assertSessionHas('status');
        $this->assertSame(Transaction::DONE, $t->fresh()->status);
        $this->assertSame('2026-11-18', $t->fresh()->date->toDateString());
        $this->get($this->url('/app/contas'))->assertSee('R$ 700,00');
        $this->asAna("/app/lancamentos/{$t->id}/confirmar")->assertSessionHasErrors('status');
    }

    public function test_parcelado_no_cartao_divide_o_valor_uma_parcela_em_cada_fatura(): void
    {
        $card = $this->account('Cartão Inter', 'cartao', ['closing_day' => 10, 'due_day' => 17]);

        $this->asAna('/app/lancamentos', ['kind' => 'gasto', 'amount' => '1.000,05', 'description' => 'Geladeira', 'account_id' => $card->id, 'date' => '2026-11-12', 'mode' => 'parcelas', 'count' => 10]);

        $rows = $this->rows();
        $this->assertCount(10, $rows);
        $this->assertSame(100005, $rows->sum('amount_cents'));
        $this->assertSame(10005, $rows[0]->amount_cents);
        $this->assertSame(10000, $rows[9]->amount_cents);
        $this->assertSame(['2026-12', '2027-01'], [$rows[0]->invoice_month, $rows[1]->invoice_month]);
        $this->assertSame('2027-09', $rows[9]->invoice_month);
        $this->assertSame([1, 10, 'parcelas'], [$rows[0]->installment, $rows[0]->installments, $rows[0]->series_kind]);
        $this->assertCount(1, $rows->pluck('series_id')->unique());
        // No cartão a compra já aconteceu: nada fica previsto.
        $this->assertSame([Transaction::DONE], $rows->pluck('status')->unique()->values()->all());

        $this->actingAs($this->ana)->get($this->url('/app/lancamentos?mes=2027-01'))->assertSee('Geladeira')->assertSee('3/10');
    }

    public function test_todo_mes_repete_o_valor_e_os_proximos_ficam_previstos(): void
    {
        $nubank = $this->account();
        $salary = $this->category('salario');

        $this->asAna('/app/lancamentos', ['kind' => 'recebimento', 'amount' => '5.000', 'description' => 'Salário', 'account_id' => $nubank->id, 'category_id' => $salary->id, 'date' => '2026-11-05', 'mode' => 'mensal', 'count' => 12]);

        $rows = $this->rows();
        $this->assertCount(12, $rows);
        $this->assertSame([500000], $rows->pluck('amount_cents')->unique()->values()->all());
        $this->assertSame(Transaction::DONE, $rows[0]->status);
        $this->assertSame(Transaction::PLANNED, $rows[1]->status);
        $this->assertSame('2027-10-05', $rows[11]->date->toDateString());
        $this->assertSame('todo mês', $rows[0]->seriesLabel());
    }

    public function test_transferencia_tira_de_uma_conta_e_poe_na_outra_e_paga_a_fatura(): void
    {
        $nubank = $this->account('Nubank', 'corrente', ['opening_balance_cents' => 200000]);
        $savings = $this->account('Poupança', 'poupanca');
        $card = $this->account('Cartão', 'cartao', ['closing_day' => 10, 'due_day' => 17]);

        $this->asAna('/app/lancamentos', ['kind' => 'transferencia', 'amount' => '500', 'description' => 'Guardar', 'account_id' => $nubank->id, 'to_account_id' => $savings->id, 'date' => '2026-11-18'])->assertSessionHasNoErrors();
        $this->asAna('/app/lancamentos', ['kind' => 'gasto', 'amount' => '300', 'description' => 'Restaurante', 'account_id' => $card->id, 'date' => '2026-11-05']);
        $this->asAna('/app/lancamentos', ['kind' => 'transferencia', 'amount' => '300', 'description' => 'Fatura de novembro', 'account_id' => $nubank->id, 'to_account_id' => $card->id, 'date' => '2026-11-17']);

        $balances = $this->inWallet(fn () => app(\App\Finance\Transactions\TransactionService::class)->balances());
        $this->assertSame(120000, $balances[$nubank->id]);
        $this->assertSame(50000, $balances[$savings->id]);

        // Transferência não é gasto: não entra no "saiu".
        $this->actingAs($this->ana)->get($this->url('/app'))->assertSee('R$ 300,00')->assertDontSee('R$ 1.100,00');

        $this->asAna('/app/lancamentos', ['kind' => 'transferencia', 'amount' => '10', 'description' => 'X', 'account_id' => $nubank->id, 'to_account_id' => $nubank->id, 'date' => '2026-11-18'])
            ->assertSessionHasErrors('to_account_id');
    }

    public function test_recusa_categoria_do_tipo_errado_e_conta_de_outra_carteira(): void
    {
        $nubank = $this->account();
        $other = app(OpenWallet::class)->open(User::factory()->create(), 'Outra');
        $foreign = $this->inWallet(fn () => Account::create(['kind' => 'corrente', 'name' => 'Conta alheia']), $other);

        $this->asAna('/app/lancamentos', ['kind' => 'gasto', 'amount' => '10', 'description' => 'X', 'account_id' => $nubank->id, 'category_id' => $this->category('salario')->id, 'date' => '2026-11-18'])
            ->assertSessionHasErrors('category_id');
        $this->asAna('/app/lancamentos', ['kind' => 'gasto', 'amount' => '10', 'description' => 'X', 'account_id' => $foreign->id, 'date' => '2026-11-18'])
            ->assertSessionHasErrors('account_id');
        $this->asAna('/app/lancamentos', ['kind' => 'gasto', 'amount' => '0', 'description' => 'X', 'account_id' => $nubank->id, 'date' => '2026-11-18'])
            ->assertSessionHasErrors('amount');
        $this->assertSame(0, Transaction::withoutGlobalScopes()->count());
    }

    public function test_lancamento_de_outra_carteira_nao_abre(): void
    {
        $other = app(OpenWallet::class)->open($bia = User::factory()->create(), 'Da Bia');
        $foreign = app(ContextStore::class)->run(ExecutionContext::forUser($other->organization, $bia->id), function () {
            $account = Account::create(['kind' => 'corrente', 'name' => 'Bia']);

            return Transaction::create(['account_id' => $account->id, 'kind' => 'gasto', 'amount_cents' => 100, 'date' => '2026-11-18', 'description' => 'Segredo']);
        });

        $this->actingAs($this->ana)->get($this->url("/app/lancamentos/{$foreign->id}"))->assertNotFound();
        $this->asAna("/app/lancamentos/{$foreign->id}/apagar")->assertNotFound();
        $this->get($this->url('/app/lancamentos'))->assertDontSee('Segredo');
        $this->assertNotNull(Transaction::withoutGlobalScopes()->find($foreign->id));
    }

    public function test_editar_e_apagar_esta_e_as_proximas_da_serie(): void
    {
        $nubank = $this->account();
        $this->asAna('/app/lancamentos', ['kind' => 'gasto', 'amount' => '1.200', 'description' => 'Aluguel', 'account_id' => $nubank->id, 'date' => '2026-11-10', 'mode' => 'mensal', 'count' => 6]);
        $rows = $this->rows();

        // Aluguel subiu a partir de janeiro: muda janeiro e os seguintes, não novembro e dezembro.
        $this->asAna("/app/lancamentos/{$rows[2]->id}", ['amount' => '1.300', 'description' => 'Aluguel reajustado', 'account_id' => $nubank->id, 'date' => '2027-01-10', 'following' => 1])->assertSessionHasNoErrors();
        $rows = $this->rows();
        $this->assertSame([120000, 120000, 130000, 130000, 130000, 130000], $rows->pluck('amount_cents')->all());
        $this->assertSame('Aluguel', $rows[1]->description);
        $this->assertSame('Aluguel reajustado', $rows[5]->description);

        // Só este: muda só ele.
        $this->asAna("/app/lancamentos/{$rows[3]->id}", ['amount' => '50', 'description' => 'Multa', 'account_id' => $nubank->id, 'date' => '2027-02-11']);
        $this->assertSame(5000, $rows[3]->fresh()->amount_cents);
        $this->assertSame(130000, $rows[4]->fresh()->amount_cents);

        // Mudou-se em abril: apaga abril em diante.
        $this->asAna("/app/lancamentos/{$rows[5]->id}/apagar", ['following' => 1]);
        $this->assertCount(5, $this->rows());
        $this->asAna("/app/lancamentos/{$rows[2]->id}/apagar", ['following' => 1]);
        $this->assertCount(2, $this->rows());
        $this->assertSame(4, Transaction::withoutGlobalScopes()->onlyTrashed()->count());
    }

    public function test_quem_so_ve_nao_lanca(): void
    {
        $nubank = $this->account();
        $viewer = User::factory()->create();
        Membership::create(['organization_id' => $this->wallet->organization_id, 'user_id' => $viewer->id, 'role' => 'viewer']);

        $this->actingAs($viewer)->get($this->url('/app/lancamentos'))->assertOk()->assertDontSee('name="amount"', false);
        $this->post($this->url('/app/lancamentos'), ['kind' => 'gasto', 'amount' => '10', 'description' => 'X', 'account_id' => $nubank->id, 'date' => '2026-11-18'])->assertForbidden();
        $this->post($this->url('/app/contas'), ['kind' => 'corrente', 'name' => 'X'])->assertForbidden();
        $this->get($this->url('/app/contas'))->assertOk();
        $this->get($this->url('/app/categorias'))->assertOk();
    }

    public function test_filtros_por_categoria_geral_conta_e_busca(): void
    {
        $nubank = $this->account();
        $cash = $this->account('Carteira', 'dinheiro');
        $this->inWallet(function () use ($nubank, $cash) {
            $service = app(\App\Finance\Transactions\TransactionService::class);
            $service->create(['kind' => 'gasto', 'amount_cents' => 5000, 'date' => '2026-11-03', 'description' => 'iFood pizza', 'account_id' => $nubank->id, 'category_id' => Category::where('system_key', 'delivery')->value('id')]);
            $service->create(['kind' => 'gasto', 'amount_cents' => 8000, 'date' => '2026-11-04', 'description' => 'Restaurante japonês', 'account_id' => $cash->id, 'category_id' => Category::where('system_key', 'restaurante')->value('id')]);
            $service->create(['kind' => 'gasto', 'amount_cents' => 20000, 'date' => '2026-11-05', 'description' => 'Mercado', 'account_id' => $nubank->id, 'category_id' => Category::where('system_key', 'mercado')->value('id')]);
            $service->create(['kind' => 'gasto', 'amount_cents' => 999, 'date' => '2026-10-30', 'description' => 'Mês passado', 'account_id' => $nubank->id]);
        });
        $comerFora = $this->category('alimentacao');

        $this->actingAs($this->ana)->get($this->url('/app/lancamentos?categoria='.$comerFora->id))
            ->assertSee('iFood pizza')->assertSee('Restaurante japonês')->assertDontSee('R$ 200,00')->assertSee('R$ 130,00');
        $this->get($this->url('/app/lancamentos?conta='.$cash->id))->assertSee('Restaurante japonês')->assertDontSee('iFood pizza');
        $this->get($this->url('/app/lancamentos?busca=ifood'))->assertSee('iFood pizza')->assertDontSee('Restaurante japonês');
        $this->get($this->url('/app/lancamentos'))->assertDontSee('Mês passado');
        $this->get($this->url('/app/lancamentos?mes=2026-10'))->assertSee('Mês passado');

        // Início: onde mais gastou (o grupo soma as subcategorias).
        $this->get($this->url('/app'))->assertSee('Onde você mais gastou')->assertSee('Mercado')->assertSee('Comer fora')->assertSee('61% do que saiu foi em Mercado');
    }

    public function test_exporta_planilha_e_pdf_com_os_filtros(): void
    {
        $nubank = $this->account();
        $this->asAna('/app/lancamentos', ['kind' => 'gasto', 'amount' => '42,90', 'description' => 'Farmácia', 'account_id' => $nubank->id, 'date' => '2026-11-18', 'notes' => 'remédio da Bia']);
        $this->asAna('/app/lancamentos', ['kind' => 'recebimento', 'amount' => '100', 'description' => 'Reembolso', 'account_id' => $nubank->id, 'date' => '2026-11-18']);

        $csv = $this->actingAs($this->ana)->get($this->url('/app/lancamentos?exportar=planilha&tipo=gasto'));
        $content = $csv->streamedContent();
        $this->assertStringContainsString('Farmácia', $content);
        $this->assertStringContainsString('-42,90', $content);
        $this->assertStringContainsString('remédio da Bia', $content);
        $this->assertStringNotContainsString('Reembolso', $content);

        $this->get($this->url('/app/lancamentos?exportar=pdf'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get($this->url('/app/contas?exportar=planilha'))->assertOk();
        $this->get($this->url('/app/categorias?exportar=planilha'))->assertOk();
    }

    public function test_observacao_fica_criptografada_no_banco(): void
    {
        $nubank = $this->account();
        $this->asAna('/app/lancamentos', ['kind' => 'gasto', 'amount' => '10', 'description' => 'Consulta', 'account_id' => $nubank->id, 'date' => '2026-11-18', 'notes' => 'psicóloga']);

        $raw = \DB::table('finance_transactions')->value('notes');
        $this->assertStringNotContainsString('psicóloga', $raw);
        $this->assertSame('psicóloga', $this->rows()->first()->notes);
    }

    public function test_categorias_criar_esconder_e_juntar(): void
    {
        $lazer = $this->category('lazer');
        $this->asAna('/app/categorias', ['kind' => 'gasto', 'name' => 'Cinema', 'parent_id' => $lazer->id])->assertSessionHasNoErrors();
        $cinema = $this->inWallet(fn () => Category::where('name', 'Cinema')->firstOrFail());
        $this->assertSame($lazer->id, $cinema->parent_id);

        $this->asAna('/app/categorias', ['kind' => 'recebimento', 'name' => 'X', 'parent_id' => $lazer->id])->assertSessionHasErrors('parent_id');

        $this->asAna("/app/categorias/{$cinema->id}/esconder", ['hide' => 1]);
        $this->actingAs($this->ana)->get($this->url('/app/categorias'))->assertSee('escondida');
        $this->assertArrayNotHasKey($cinema->id, $this->inWallet(fn () => Category::options()['gasto']['Lazer']));

        // Juntar: os lançamentos passam para a outra.
        $nubank = $this->account();
        $passeios = $this->category('passeios');
        $this->asAna('/app/lancamentos', ['kind' => 'gasto', 'amount' => '30', 'description' => 'Ingresso', 'account_id' => $nubank->id, 'category_id' => $passeios->id, 'date' => '2026-11-18']);
        $this->asAna("/app/categorias/{$passeios->id}/juntar", ['target_id' => $cinema->id])->assertSessionHasNoErrors();
        $this->assertSame($cinema->id, $this->rows()->first()->category_id);
        $this->assertNull($this->inWallet(fn () => Category::find($passeios->id)));

        $this->asAna("/app/categorias/{$cinema->id}/juntar", ['target_id' => $this->category('salario')->id])->assertSessionHasErrors('target_id');
    }

    public function test_conta_com_lancamentos_arquiva_mas_nao_exclui(): void
    {
        $nubank = $this->account();
        $empty = $this->account('Sem uso');
        $this->asAna('/app/lancamentos', ['kind' => 'gasto', 'amount' => '10', 'description' => 'X', 'account_id' => $nubank->id, 'date' => '2026-11-18']);

        $this->asAna("/app/contas/{$nubank->id}/excluir")->assertSessionHasErrors('account');
        $this->asAna("/app/contas/{$nubank->id}/arquivar", ['archive' => 1]);
        $this->assertNotNull($nubank->fresh()->archived_at);
        // Arquivada some do formulário de lançar.
        $this->asAna('/app/lancamentos', ['kind' => 'gasto', 'amount' => '10', 'description' => 'Y', 'account_id' => $nubank->id, 'date' => '2026-11-18'])->assertSessionHasErrors('account_id');

        $this->asAna("/app/contas/{$empty->id}/excluir")->assertSessionHasNoErrors();
        $this->assertNull(Account::withoutGlobalScopes()->find($empty->id));
    }

    public function test_foxy_admin_recebe_so_contagens(): void
    {
        $nubank = $this->account();
        $this->asAna('/app/lancamentos', ['kind' => 'gasto', 'amount' => '777,77', 'description' => 'Segredo', 'account_id' => $nubank->id, 'date' => '2026-11-18']);

        $this->artisan('financas:contas')->assertSuccessful();
        $account = ProductAccount::where('external_ref', 'carteira-'.$this->wallet->organization_id)->firstOrFail();
        $this->assertSame(1, $account->usage['lançamentos']);
        $this->assertSame(1, $account->usage['contas']);
        $this->assertNotNull($account->last_activity_at);
        $this->assertStringNotContainsString('777', json_encode($account->toArray()));
        $this->assertStringNotContainsString('Segredo', json_encode($account->toArray()));
    }
}
