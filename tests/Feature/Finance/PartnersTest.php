<?php

namespace Tests\Feature\Finance;

use App\Finance\Accounts\Account;
use App\Finance\Categories\Category;
use App\Finance\Insights\Indicators;
use App\Finance\Partners\Partner;
use App\Finance\Partners\PartnerService;
use App\Finance\Statements\StatementImporter;
use App\Finance\Statements\StatementLine;
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
 * Foxy Finanças, fatia 7 (docs/12, seção 7): sócios — a parte de cada um, lucro, quanto cabe, quanto tirou,
 * acerto; retirada que entra sozinha na carteira pessoal do sócio; MEI (faturamento no ano e DAS).
 */
class PartnersTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private User $bruno;

    private Wallet $business;

    private Wallet $anaPersonal;

    private Account $pj;

    private Account $anaPf;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-19 10:00:00');
        $this->ana = User::factory()->create(['name' => 'Ana']);
        $this->bruno = User::factory()->create(['name' => 'Bruno']);
        $this->business = app(OpenWallet::class)->open($this->ana, 'Ateliê', Wallet::BUSINESS, true);
        $this->anaPersonal = app(OpenWallet::class)->open($this->ana, 'Pessoal');
        Membership::create(['organization_id' => $this->business->organization_id, 'user_id' => $this->bruno->id, 'role' => 'member']);
        $this->pj = $this->in(fn () => Account::create(['kind' => 'corrente', 'name' => 'Conta PJ']));
        $this->anaPf = $this->in(fn () => Account::create(['kind' => 'corrente', 'name' => 'Nubank PF']), $this->anaPersonal);
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

    private function in(callable $fn, ?Wallet $wallet = null, ?User $user = null)
    {
        return app(ContextStore::class)->run(ExecutionContext::forUser(($wallet ?? $this->business)->organization, ($user ?? $this->ana)->id), $fn);
    }

    /** Ação na carteira do negócio, como $user. */
    private function act(string $path, array $data = [], ?User $user = null)
    {
        return $this->actingAs($user ?? $this->ana)->withSession(['finance.organization_id' => $this->business->organization_id])->call('POST', $this->url($path), $data);
    }

    private function partner(string $name): Partner
    {
        return $this->in(fn () => Partner::where('name', $name)->firstOrFail());
    }

    /** 50% / 50%; vendas 10.000 e gastos 4.000 em novembro: lucro 6.000, cabe 3.000 a cada um. */
    private function scenario(): void
    {
        $this->act('/app/socios', ['name' => 'Ana', 'share' => '50', 'user_id' => $this->ana->id])->assertSessionHasNoErrors();
        $this->act('/app/socios', ['name' => 'Bruno', 'share' => '50', 'user_id' => $this->bruno->id])->assertSessionHasNoErrors();
        $this->in(function () {
            $svc = app(TransactionService::class);
            $svc->create(['kind' => 'recebimento', 'amount_cents' => 1000000, 'date' => '2026-11-05', 'description' => 'Vendas', 'account_id' => $this->pj->id]);
            $svc->create(['kind' => 'gasto', 'amount_cents' => 400000, 'date' => '2026-11-06', 'description' => 'Tecidos', 'account_id' => $this->pj->id]);
        });
    }

    private function summary(): array
    {
        return $this->in(fn () => app(PartnerService::class)->summary(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30')));
    }

    public function test_lucro_quanto_cabe_quanto_tirou_e_o_acerto(): void
    {
        $this->scenario();
        $this->act('/app/socios/movimentos', ['partner_id' => $this->partner('Ana')->id, 'kind' => 'pro_labore', 'amount' => '2.000', 'date' => '2026-11-10', 'account_id' => $this->pj->id]);
        $this->act('/app/socios/movimentos', ['partner_id' => $this->partner('Bruno')->id, 'kind' => 'retirada', 'amount' => '3.400', 'date' => '2026-11-12', 'account_id' => $this->pj->id], $this->bruno)->assertSessionHasNoErrors();

        $s = $this->summary();
        $this->assertSame([1000000, 400000, 600000], [$s['income'], $s['expense'], $s['profit']], 'Pró-labore e retirada não entram no lucro.');
        $rows = $s['partners']->keyBy(fn ($r) => $r['partner']->name);
        $this->assertSame([300000, 200000, -100000], [$rows['Ana']['due'], $rows['Ana']['took'], $rows['Ana']['diff']]);
        $this->assertSame([300000, 340000, 40000], [$rows['Bruno']['due'], $rows['Bruno']['took'], $rows['Bruno']['diff']]);

        $suggested = $this->in(fn () => app(PartnerService::class)->suggestedSettlements($s['partners']));
        $this->assertSame(['Bruno', 'Ana', 40000], [$suggested[0]['from']->name, $suggested[0]['to']->name, $suggested[0]['amount']]);
        $this->actingAs($this->ana)->withSession(['finance.organization_id' => $this->business->organization_id])->get($this->url('/app/socios'))
            ->assertOk()->assertSee('tirou R$ 400,00 a mais')->assertSee('Bruno pagou R$ 400,00 a Ana');

        // Acerto por fora do caixa: zera a diferença do Bruno.
        $this->act('/app/socios/acertos', ['from_partner_id' => $this->partner('Bruno')->id, 'to_partner_id' => $this->partner('Ana')->id, 'amount' => '400', 'date' => '2026-11-19']);
        $rows = $this->summary()['partners']->keyBy(fn ($r) => $r['partner']->name);
        $this->assertSame([0, -60000], [$rows['Bruno']['diff'], $rows['Ana']['diff']]);

        // No negócio, os indicadores (gasto, categorias) não contam os movimentos de sócio.
        $this->assertSame(400000, $this->in(fn () => Indicators::for(Carbon::parse('2026-11-01'))->summary()['expense']));
    }

    public function test_aporte_entra_no_caixa_sem_ser_venda(): void
    {
        $this->scenario();
        $this->act('/app/socios/movimentos', ['partner_id' => $this->partner('Bruno')->id, 'kind' => 'aporte', 'amount' => '1.000', 'date' => '2026-11-10', 'account_id' => $this->pj->id]);

        $s = $this->summary();
        $this->assertSame(1000000, $s['income']);
        $this->assertSame(-100000, $s['partners']->firstWhere(fn ($r) => $r['partner']->name === 'Bruno')['took']);
        $this->assertSame(700000, $this->in(fn () => app(TransactionService::class)->balances())[$this->pj->id]);
    }

    public function test_retirada_entra_sozinha_na_carteira_pessoal_do_socio_que_aceitou(): void
    {
        $this->scenario();
        $ana = $this->partner('Ana');

        // O Bruno não decide pela Ana.
        $this->act("/app/socios/{$ana->id}/minha-carteira", ['mirror_account_id' => $this->anaPf->id], $this->bruno)->assertSessionHasErrors('mirror');
        $this->actingAs($this->ana)->withSession(['finance.organization_id' => $this->business->organization_id])->get($this->url('/app/socios'))->assertSee('Minhas retiradas na minha carteira pessoal')->assertSee('Nubank PF');
        $this->act("/app/socios/{$ana->id}/minha-carteira", ['mirror_account_id' => $this->anaPf->id])->assertSessionHasNoErrors();

        $this->act('/app/socios/movimentos', ['partner_id' => $ana->id, 'kind' => 'pro_labore', 'amount' => '2.000', 'date' => '2026-11-10', 'account_id' => $this->pj->id])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'carteira pessoal'));

        $business = $this->in(fn () => Transaction::where('partner_id', $ana->id)->firstOrFail());
        $personal = $this->in(fn () => Transaction::firstOrFail(), $this->anaPersonal);
        $proLabore = $this->in(fn () => Category::where('system_key', 'pro_labore')->value('id'), $this->anaPersonal);
        $this->assertSame(['recebimento', 200000, $this->anaPf->id, $proLabore, $business->id], [$personal->kind, $personal->amount_cents, $personal->account_id, $personal->category_id, $personal->linked_transaction_id]);
        $this->assertSame($personal->id, $business->linked_transaction_id);
        $this->assertSame('Pró-labore de Ateliê', $personal->description);

        // Mudou o valor no negócio: a carteira pessoal acompanha. Apagou no negócio: some de lá também.
        $this->act("/app/lancamentos/{$business->id}", ['amount' => '2.500', 'description' => $business->description, 'account_id' => $this->pj->id, 'date' => '2026-11-11']);
        $this->assertSame([250000, '2026-11-11'], [$personal->fresh()->amount_cents, $personal->fresh()->date->toDateString()]);
        $this->act("/app/lancamentos/{$business->id}/apagar");
        $this->assertSoftDeleted('finance_transactions', ['id' => $personal->id]);
    }

    public function test_apagar_a_copia_na_carteira_pessoal_nao_apaga_o_negocio(): void
    {
        $this->scenario();
        $ana = $this->partner('Ana');
        $this->act("/app/socios/{$ana->id}/minha-carteira", ['mirror_account_id' => $this->anaPf->id]);
        $this->act('/app/socios/movimentos', ['partner_id' => $ana->id, 'kind' => 'retirada', 'amount' => '500', 'date' => '2026-11-10', 'account_id' => $this->pj->id]);
        $personal = $this->in(fn () => Transaction::firstOrFail(), $this->anaPersonal);

        $this->actingAs($this->ana)->withSession(['finance.organization_id' => $this->anaPersonal->organization_id])->call('POST', $this->url("/app/lancamentos/{$personal->id}/apagar"));
        $business = $this->in(fn () => Transaction::where('partner_id', $ana->id)->firstOrFail());
        $this->assertNull($business->linked_transaction_id);
        $this->assertSame(50000, $business->amount_cents);
    }

    public function test_gasto_pessoal_pago_pela_conta_do_negocio_vira_retirada(): void
    {
        $this->scenario();
        $this->in(fn () => app(StatementImporter::class)->import($this->pj, 'ofx', 'x.ofx', [
            ['date' => '2026-11-15', 'time' => null, 'description' => 'NETFLIX', 'amount' => -55.9, 'external_id' => 'N1'],
        ]));
        $line = $this->in(fn () => StatementLine::firstOrFail());

        $this->actingAs($this->ana)->withSession(['finance.organization_id' => $this->business->organization_id])->get($this->url('/app/extratos'))->assertSee('É gasto pessoal do sócio');
        $this->act("/app/extratos/linhas/{$line->id}/retirada", ['partner_id' => $this->partner('Bruno')->id])->assertSessionHas('status');

        $this->assertSame(StatementLine::CREATED, $line->fresh()->status);
        $t = $this->in(fn () => Transaction::where('statement_line_id', $line->id)->firstOrFail());
        $this->assertSame(['retirada', 5590], [$t->partner_kind, $t->amount_cents]);
        $this->assertSame(400000, $this->summary()['expense'], 'Não virou gasto do negócio.');
    }

    public function test_mei_faturamento_no_ano_e_lembrete_do_das(): void
    {
        $this->scenario();
        $mei = $this->in(fn () => app(PartnerService::class)->meiYear(2026));
        $this->assertSame([1000000, 7100000], [$mei['total'], $mei['left']]);

        $this->act('/app/socios/das', ['account_id' => $this->pj->id, 'amount' => '81,05'])->assertSessionHas('status', fn ($s) => str_contains($s, '12 meses'));
        $das = $this->in(fn () => Transaction::where('description', 'DAS do MEI')->orderBy('date')->get());
        $this->assertCount(12, $das);
        $this->assertSame(['2026-11-20', Transaction::PLANNED, 8105], [$das[0]->date->toDateString(), $das[0]->status, $das[0]->amount_cents]);
        $this->assertSame($this->in(fn () => Category::where('system_key', 'das')->value('id')), $das[0]->category_id);

        $this->actingAs($this->ana)->withSession(['finance.organization_id' => $this->business->organization_id])->get($this->url('/app/socios'))
            ->assertSee('MEI em 2026')->assertSee('12% do limite')->assertDontSee('Criar o lembrete do DAS');
    }

    public function test_partes_e_permissoes(): void
    {
        $this->act('/app/socios', ['name' => 'Ana', 'share' => '60']);
        $this->act('/app/socios', ['name' => 'Bruno', 'share' => '50'])->assertSessionHas('status', fn ($s) => str_contains($s, 'somam 110%'));
        // Login só de quem está na carteira.
        $outsider = User::factory()->create();
        $this->act('/app/socios', ['name' => 'X', 'share' => '0', 'user_id' => $outsider->id])->assertSessionHasErrors('user_id');

        // Membro registra movimento, mas não cadastra sócio; quem só vê não registra nada.
        $this->act('/app/socios', ['name' => 'Caio', 'share' => '10'], $this->bruno)->assertForbidden();
        $viewer = User::factory()->create();
        Membership::create(['organization_id' => $this->business->organization_id, 'user_id' => $viewer->id, 'role' => 'viewer']);
        $this->act('/app/socios/movimentos', ['partner_id' => $this->partner('Ana')->id, 'kind' => 'retirada', 'amount' => '1', 'date' => '2026-11-19', 'account_id' => $this->pj->id], $viewer)->assertForbidden();

        // Carteira pessoal não tem sócios.
        $this->actingAs($this->ana)->withSession(['finance.organization_id' => $this->anaPersonal->organization_id])->get($this->url('/app/socios'))->assertNotFound();
    }
}
