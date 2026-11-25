<?php

namespace Tests\Feature\Finance;

use App\Finance\Accounts\Account;
use App\Finance\Debts\Contact;
use App\Finance\Goals\Goal;
use App\Finance\Transactions\Transaction;
use App\Finance\Transactions\TransactionService;
use App\Finance\Wallets\OpenWallet;
use App\Finance\Wallets\Wallet;
use App\Models\User;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Memberships\Membership;
use App\Platform\Organizations\Organization;
use App\Platform\Products\ProductAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Foxy Finanças, fatia 11 (docs/12, seção 10): exportar tudo, apagar a carteira e apagar a conta.
 */
class PrivacyTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private Wallet $personal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ana = User::factory()->create(['name' => 'Ana', 'password' => Hash::make('segredo-123')]);
        $this->personal = app(OpenWallet::class)->open($this->ana, 'Pessoal');
    }

    private function url(string $path): string
    {
        return 'http://'.config('foxy.hosts.financas').$path;
    }

    private function in(Wallet $wallet, callable $fn, ?User $user = null)
    {
        return app(ContextStore::class)->run(ExecutionContext::forUser($wallet->organization, ($user ?? $this->ana)->id), $fn);
    }

    private function as(User $user, Wallet $wallet)
    {
        return $this->actingAs($user)->withSession(['finance.organization_id' => $wallet->organization_id]);
    }

    /** Uma carteira com um pouco de tudo. */
    private function fill(Wallet $wallet): void
    {
        $this->in($wallet, function () {
            $account = Account::create(['kind' => 'corrente', 'name' => 'Nubank']);
            app(TransactionService::class)->create([
                'account_id' => $account->id, 'kind' => 'gasto', 'amount_cents' => 4290, 'date' => today()->toDateString(),
                'description' => 'Mercado do bairro', 'status' => 'efetivado',
            ]);
            Goal::create(['name' => 'Viagem', 'target_cents' => 300000]);
            Contact::create(['name' => 'Carla', 'phone' => '11999998888']);
        });
    }

    public function test_exportar_tudo_traz_lancamentos_contas_e_metas_so_desta_carteira(): void
    {
        $this->fill($this->personal);
        $other = app(OpenWallet::class)->open($this->ana, 'Ateliê', Wallet::BUSINESS);
        $this->in($other, fn () => Account::create(['kind' => 'corrente', 'name' => 'Conta do ateliê']));

        $response = $this->as($this->ana, $this->personal)->get($this->url('/app/carteira/exportar?exportar=planilha'));

        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Mercado do bairro', $csv);
        $this->assertStringContainsString('Nubank', $csv);
        $this->assertStringContainsString('Carla', $csv);
        $this->assertStringContainsString('Lançamentos', $csv);
        $this->assertStringNotContainsString('Conta do ateliê', $csv);

        $this->as($this->ana, $this->personal)->get($this->url('/app/carteira/exportar?exportar=pdf'))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_quem_nao_e_dono_nao_exporta_nem_apaga(): void
    {
        $bia = User::factory()->create(['password' => Hash::make('segredo-123')]);
        Membership::create(['organization_id' => $this->personal->organization_id, 'user_id' => $bia->id, 'role' => 'member']);

        $this->as($bia, $this->personal)->get($this->url('/app/carteira/exportar'))->assertForbidden();
        $this->as($bia, $this->personal)->post($this->url('/app/carteira/apagar'), ['password' => 'segredo-123', 'confirm' => '1'])->assertForbidden();
        $this->assertSame(1, Wallet::withoutGlobalScopes()->count());
    }

    public function test_apagar_carteira_pede_a_senha_e_apaga_tudo_dela_e_nada_das_outras(): void
    {
        $this->fill($this->personal);
        $other = app(OpenWallet::class)->open($this->ana, 'Ateliê', Wallet::BUSINESS);
        $this->fill($other);
        $id = $this->personal->organization_id;

        $this->as($this->ana, $this->personal)->post($this->url('/app/carteira/apagar'), ['password' => 'errada', 'confirm' => '1'])
            ->assertSessionHasErrors('password');
        $this->assertSame(1, Transaction::withoutGlobalScopes()->where('organization_id', $id)->count());

        $this->as($this->ana, $this->personal)->post($this->url('/app/carteira/apagar'), ['password' => 'segredo-123', 'confirm' => '1'])
            ->assertRedirect(route('finance.wallets.choose'));

        foreach (['finance_transactions', 'finance_accounts', 'finance_categories', 'finance_goals', 'finance_contacts', 'finance_wallets', 'memberships'] as $table) {
            $this->assertSame(0, DB::table($table)->where('organization_id', $id)->count(), $table);
        }
        $this->assertSame('deleted', Organization::find($id)->status);
        $this->assertSame('canceled', ProductAccount::where('external_ref', Organization::find($id)->external_ref)->value('status'));
        // A outra carteira continua inteira.
        $this->assertSame(1, Transaction::withoutGlobalScopes()->where('organization_id', $other->organization_id)->count());
        $this->as($this->ana, $other)->get($this->url('/app'))->assertOk();
        $this->artisan('financas:conquistas')->assertSuccessful();
        $this->artisan('financas:cartoes')->assertSuccessful();
    }

    public function test_apagar_conta_apaga_as_carteiras_so_dela_e_sai_das_outras(): void
    {
        $this->fill($this->personal);
        $bruno = User::factory()->create(['name' => 'Bruno']);
        $house = app(OpenWallet::class)->open($bruno, 'Casa');
        Membership::create(['organization_id' => $house->organization_id, 'user_id' => $this->ana->id, 'role' => 'member']);
        $this->fill($house);

        $this->as($this->ana, $this->personal)->get($this->url('/app/perfil'))
            ->assertOk()->assertSee('Apagar minha conta')->assertSee('apagadas de vez');

        $this->as($this->ana, $this->personal)->post($this->url('/app/perfil/apagar'), ['erase_password' => 'errada', 'confirm' => '1'])
            ->assertSessionHasErrors('erase_password');

        $this->as($this->ana, $this->personal)->post($this->url('/app/perfil/apagar'), ['erase_password' => 'segredo-123', 'confirm' => '1'])
            ->assertRedirect(route('finance.login'));

        $this->assertGuest();
        $this->assertSame(0, DB::table('finance_transactions')->where('organization_id', $this->personal->organization_id)->count());
        // A carteira do Bruno fica, sem a Ana.
        $this->assertSame(1, DB::table('finance_transactions')->where('organization_id', $house->organization_id)->count());
        $this->assertFalse(Membership::where('organization_id', $house->organization_id)->where('user_id', $this->ana->id)->exists());
        // Sem outro produto: o cadastro fica anônimo e o login antigo não entra mais.
        $ana = $this->ana->fresh();
        $this->assertSame('Conta apagada', $ana->name);
        $this->assertStringEndsWith('@apagada.invalid', $ana->email);
        $this->post($this->url('/entrar'), ['email' => 'ana@example.com', 'password' => 'segredo-123']);
        $this->assertGuest();
    }

    public function test_dona_com_outra_dona_so_sai_da_carteira(): void
    {
        $this->fill($this->personal);
        $bia = User::factory()->create();
        Membership::create(['organization_id' => $this->personal->organization_id, 'user_id' => $bia->id, 'role' => 'owner']);

        $this->as($this->ana, $this->personal)->post($this->url('/app/perfil/apagar'), ['erase_password' => 'segredo-123', 'confirm' => '1'])
            ->assertRedirect(route('finance.login'));

        $this->assertSame(1, DB::table('finance_transactions')->where('organization_id', $this->personal->organization_id)->count());
        $this->assertSame('active', Organization::find($this->personal->organization_id)->status);
    }
}
