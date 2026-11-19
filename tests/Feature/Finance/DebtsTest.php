<?php

namespace Tests\Feature\Finance;

use App\Finance\Accounts\Account;
use App\Finance\Categories\Category;
use App\Finance\Debts\Contact;
use App\Finance\Debts\PersonEntry;
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
 * Foxy Finanças, fatia 6c: "Quem me deve / a quem eu devo" — emprestar e receber de volta sem virar gasto nem
 * renda, dividir uma conta (o seu gasto é só a sua parte), objetos emprestados e o lembrete pelo WhatsApp.
 */
class DebtsTest extends TestCase
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

    private function asAna(string $path, array $data = [])
    {
        return $this->actingAs($this->ana)->call('POST', $this->url($path), $data);
    }

    private function contact(string $name): Contact
    {
        return $this->in(fn () => Contact::where('name', $name)->firstOrFail());
    }

    private function balance(string $name): int
    {
        return $this->in(fn () => $this->contact($name)->balanceCents());
    }

    public function test_emprestar_e_receber_de_volta_nao_e_gasto_nem_renda(): void
    {
        $this->asAna('/app/quem-me-deve', ['contact_name' => 'João', 'kind' => 'emprestei', 'amount' => '300', 'date' => '2026-11-10', 'account_id' => $this->bank->id, 'description' => 'conserto do carro', 'due_date' => '2026-12-10'])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'João te deve R$ 300,00'));
        $this->assertSame(30000, $this->balance('João'));

        $t = $this->in(fn () => Transaction::firstOrFail());
        $people = $this->in(fn () => Account::where('kind', Account::PEOPLE)->firstOrFail());
        $this->assertSame(['transferencia', $this->bank->id, $people->id], [$t->kind, $t->account_id, $t->to_account_id]);
        $this->assertSame(70000, $this->in(fn () => app(TransactionService::class)->balances())[$this->bank->id]);

        // Não entra no "saiu" do mês, e a conta interna não aparece nos formulários nem em Contas.
        $this->actingAs($this->ana)->get($this->url('/app'))->assertViewHas('expense', 0)->assertViewHas('income', 0)->assertSee('Te devem');
        $this->actingAs($this->ana)->get($this->url('/app/contas'))->assertDontSee('Amigos e família');
        $this->actingAs($this->ana)->get($this->url('/app/lancamentos'))->assertViewHas('activeAccounts', fn ($a) => ! $a->contains('id', $people->id));

        // Devolveu uma parte, na conta; não pode devolver mais do que deve.
        $this->asAna('/app/quem-me-deve', ['contact_id' => $this->contact('João')->id, 'kind' => 'devolveu', 'amount' => '500', 'date' => '2026-11-19'])->assertSessionHasErrors('amount');
        $this->asAna('/app/quem-me-deve', ['contact_id' => $this->contact('João')->id, 'kind' => 'devolveu', 'amount' => '100', 'date' => '2026-11-19', 'account_id' => $this->bank->id]);
        $this->assertSame(20000, $this->balance('João'));
        $this->assertSame(80000, $this->in(fn () => app(TransactionService::class)->balances())[$this->bank->id]);

        $this->actingAs($this->ana)->get($this->url('/app/quem-me-deve'))->assertOk()->assertSee('Te devem')->assertSee('João')->assertSee('R$ 200,00');
    }

    public function test_peguei_emprestado_e_paguei(): void
    {
        $this->asAna('/app/quem-me-deve', ['contact_name' => 'Carla', 'kind' => 'peguei', 'amount' => '40', 'date' => '2026-11-18']);
        $this->assertSame(-4000, $this->balance('Carla'));
        $this->actingAs($this->ana)->get($this->url('/app/quem-me-deve'))->assertSee('Você deve')->assertSee('R$ 40,00');

        $this->asAna('/app/quem-me-deve', ['contact_id' => $this->contact('Carla')->id, 'kind' => 'paguei', 'amount' => '50', 'date' => '2026-11-19'])->assertSessionHasErrors('amount');
        $this->asAna('/app/quem-me-deve', ['contact_id' => $this->contact('Carla')->id, 'kind' => 'paguei', 'amount' => '40', 'date' => '2026-11-19'])->assertSessionHas('status', fn ($s) => str_contains($s, 'quites'));
        $this->assertSame(0, $this->balance('Carla'));
        $this->assertSame(0, $this->in(fn () => Transaction::count()), 'Sem conta escolhida: só anota.');
    }

    public function test_mesmo_nome_e_a_mesma_pessoa(): void
    {
        $this->asAna('/app/quem-me-deve', ['contact_name' => 'José', 'kind' => 'emprestei', 'amount' => '10', 'date' => '2026-11-19']);
        $this->asAna('/app/quem-me-deve', ['contact_name' => ' jose ', 'kind' => 'emprestei', 'amount' => '5', 'date' => '2026-11-19']);
        $this->assertSame(1, $this->in(fn () => Contact::count()));
        $this->assertSame(1500, $this->balance('José'));
    }

    public function test_dividir_a_conta_o_seu_gasto_e_so_a_sua_parte(): void
    {
        $bar = $this->in(fn () => Category::where('system_key', 'restaurante')->value('id'));
        $this->asAna('/app/quem-me-deve/dividir', [
            'description' => 'Bar de sexta', 'total' => '200', 'account_id' => $this->bank->id, 'category_id' => $bar, 'date' => '2026-11-14',
            'people' => [['name' => 'João', 'amount' => '50'], ['name' => 'Bia', 'amount' => '50'], ['name' => 'Caio', 'amount' => '50'], ['name' => '', 'amount' => '']],
        ])->assertSessionHas('status', fn ($s) => str_contains($s, 'o seu gasto ficou R$ 50,00') && str_contains($s, '3 pessoa(s)'));

        $this->assertSame([5000, 5000, 5000], [$this->balance('João'), $this->balance('Bia'), $this->balance('Caio')]);
        $rows = $this->in(fn () => Transaction::orderBy('amount_cents')->get());
        $this->assertSame([['gasto', 5000, $bar], ['transferencia', 15000, null]], $rows->map(fn ($t) => [$t->kind, $t->amount_cents, $t->category_id])->all());
        $this->assertSame(80000, $this->in(fn () => app(TransactionService::class)->balances())[$this->bank->id], 'Saíram os R$ 200 da conta.');

        // A soma tem que fechar.
        $this->asAna('/app/quem-me-deve/dividir', ['description' => 'X', 'total' => '100', 'my_share' => '10', 'account_id' => $this->bank->id, 'date' => '2026-11-14', 'people' => [['name' => 'João', 'amount' => '50']]])
            ->assertSessionHasErrors('shares');

        // Apagar a divisão (de qualquer pessoa) apaga tudo dela.
        $entry = $this->in(fn () => PersonEntry::where('kind', 'dividi')->firstOrFail());
        $this->asAna("/app/quem-me-deve/movimentos/{$entry->id}/apagar")->assertSessionHas('status', fn ($s) => str_contains($s, '3 pessoas'));
        $this->assertSame([0, 0], [$this->balance('João'), $this->in(fn () => Transaction::count())]);
    }

    public function test_dividir_no_cartao_entra_na_fatura(): void
    {
        $card = $this->in(fn () => Account::create(['kind' => 'cartao', 'name' => 'Cartão', 'closing_day' => 10, 'due_day' => 17]));
        $this->asAna('/app/quem-me-deve/dividir', ['description' => 'Presente da Lu', 'total' => '300', 'my_share' => '100', 'account_id' => $card->id, 'date' => '2026-11-12',
            'people' => [['name' => 'Bia', 'amount' => '200']]]);

        $invoice = $this->in(fn () => app(\App\Finance\Accounts\CardInvoices::class)->invoice($card, '2026-12'));
        $this->assertSame(30000, $invoice['charges'], 'A fatura tem o total que você pagou.');
    }

    public function test_objeto_emprestado_e_devolvido(): void
    {
        $this->asAna('/app/quem-me-deve/objeto', ['contact_name' => 'Paulo', 'what' => 'Furadeira', 'date' => '2026-10-01']);
        $this->actingAs($this->ana)->get($this->url('/app/quem-me-deve'))->assertSee('Furadeira')->assertSee('49 dias');
        $this->assertSame(0, $this->balance('Paulo'));

        $entry = $this->in(fn () => PersonEntry::firstOrFail());
        $this->asAna("/app/quem-me-deve/movimentos/{$entry->id}/devolvido");
        $this->assertNotNull($entry->fresh()->returned_at);
        $this->actingAs($this->ana)->get($this->url('/app/quem-me-deve'))->assertSee('Nenhum objeto emprestado.');
    }

    public function test_lembrete_pelo_whatsapp_abre_o_app_da_pessoa_com_o_texto(): void
    {
        $this->asAna('/app/quem-me-deve', ['contact_name' => 'João', 'kind' => 'emprestei', 'amount' => '150', 'date' => '2026-11-10']);
        $joao = $this->contact('João');
        $this->actingAs($this->ana)->get($this->url("/app/quem-me-deve/{$joao->id}"))->assertSee('Coloque o WhatsApp da pessoa');

        $this->asAna("/app/quem-me-deve/{$joao->id}", ['name' => 'João', 'phone' => '(11) 98888-7777']);
        $page = $this->actingAs($this->ana)->get($this->url("/app/quem-me-deve/{$joao->id}"))->assertSee('Lembrar pelo WhatsApp')->getContent();
        $this->assertStringContainsString('https://wa.me/5511988887777?text=', $page);
        $this->assertStringContainsString(rawurlencode('R$ 150,00'), html_entity_decode($page));

        // Com saldo, não sai da lista.
        $this->asAna("/app/quem-me-deve/{$joao->id}/arquivar", ['archive' => 1])->assertSessionHasErrors('contact');
    }

    public function test_exportacao_e_acesso(): void
    {
        $this->asAna('/app/quem-me-deve', ['contact_name' => 'João', 'kind' => 'emprestei', 'amount' => '150', 'date' => '2026-11-10']);
        $csv = $this->actingAs($this->ana)->get($this->url('/app/quem-me-deve?exportar=planilha'))->streamedContent();
        $this->assertStringContainsString('João', $csv);
        $this->assertStringContainsString('150,00', $csv);

        $joao = $this->contact('João');
        $viewer = User::factory()->create();
        Membership::create(['organization_id' => $this->wallet->organization_id, 'user_id' => $viewer->id, 'role' => 'viewer']);
        $this->actingAs($viewer)->get($this->url('/app/quem-me-deve'))->assertOk()->assertDontSee('Dividir uma conta');
        $this->actingAs($viewer)->post($this->url('/app/quem-me-deve'), ['contact_id' => $joao->id, 'kind' => 'devolveu', 'amount' => '1', 'date' => '2026-11-19'])->assertForbidden();

        $bia = User::factory()->create();
        app(OpenWallet::class)->open($bia, 'Da Bia');
        $this->actingAs($bia)->get($this->url("/app/quem-me-deve/{$joao->id}"))->assertNotFound();
        $this->actingAs($bia)->get($this->url('/app/quem-me-deve'))->assertDontSee('R$ 150,00');
    }
}
