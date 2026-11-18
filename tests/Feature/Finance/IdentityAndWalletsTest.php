<?php

namespace Tests\Feature\Finance;

use App\Commerce\Stores\OpenStore;
use App\Finance\Finance;
use App\Finance\Wallets\InvitationNotification;
use App\Finance\Wallets\OpenWallet;
use App\Finance\Wallets\Wallet;
use App\Models\User;
use App\Platform\Memberships\Invitation;
use App\Platform\Memberships\Membership;
use App\Platform\Products\ProductAccount;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Foxy Finanças, fatia 1 (docs/12, seção 11): endereço próprio, login único, carteira pessoal e do negócio,
 * troca de carteira, pessoas na carteira (casal, família, sócios) e conta no Foxy Admin.
 */
class IdentityAndWalletsTest extends TestCase
{
    use RefreshDatabase;

    private function url(string $path): string
    {
        return 'http://'.config('foxy.hosts.financas').$path;
    }

    private function signupData(array $extra = []): array
    {
        return [
            'name' => 'Ana Souza', 'email' => 'Ana@Casa.test', 'password' => 'senha-forte-1', 'password_confirmation' => 'senha-forte-1',
        ] + $extra;
    }

    /** @return array{0: User, 1: Wallet} */
    private function owner(string $wallet = 'Pessoal', string $email = 'ana@casa.test', string $kind = Wallet::PERSONAL): array
    {
        $user = User::factory()->create(['name' => 'Ana', 'email' => $email]);

        return [$user, app(OpenWallet::class)->open($user, $wallet, $kind)];
    }

    private function member(Wallet $wallet, string $role, string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        Membership::create(['organization_id' => $wallet->organization_id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    /** Convite enviado pela tela e o token do link que chegou no e-mail. */
    private function invite(User $owner, string $email, string $role): string
    {
        Notification::fake();
        $this->actingAs($owner)->post($this->url('/app/pessoas/convites'), ['email' => $email, 'role' => $role])->assertSessionHasNoErrors();

        $url = null;
        Notification::assertSentTo(new AnonymousNotifiable, InvitationNotification::class, function ($n, $channels, $notifiable) use ($email, &$url) {
            $url = $n->url;

            return $notifiable->routes['mail'] === strtolower($email);
        });
        auth()->logout();

        return basename(parse_url($url, PHP_URL_PATH));
    }

    public function test_cadastro_fica_fechado_ate_o_lancamento(): void
    {
        $this->get($this->url('/'))->assertRedirect(rtrim(config('foxy.products.vitrine_url'), '/').'/#produtos');
        $this->get($this->url('/cadastro'))->assertOk()->assertSee('Cadastro em breve');
        $this->post($this->url('/cadastro'), $this->signupData())->assertNotFound();
        $this->assertSame(0, User::count());
    }

    public function test_quem_nao_entrou_vai_para_o_login_do_financas(): void
    {
        $this->get($this->url('/app'))->assertRedirect($this->url('/entrar'));
        $this->get($this->url('/entrar'))->assertOk()->assertSee('Foxy Finanças');
    }

    public function test_cadastro_cria_login_carteira_pessoal_e_conta_no_admin(): void
    {
        config(['finance.signup_open' => true]);
        Notification::fake();

        $this->post($this->url('/cadastro'), $this->signupData())->assertRedirect($this->url('/verificar-email'));

        $user = User::where('email', 'ana@casa.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->isStaff());

        $wallet = Wallet::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('Pessoal', $wallet->name);
        $this->assertSame(Wallet::PERSONAL, $wallet->kind);
        $this->assertSame(Finance::integration()->id, $wallet->organization->integration_id);
        $this->assertSame('owner', Membership::where('user_id', $user->id)->value('role'));

        $account = ProductAccount::where('integration_id', Finance::integration()->id)->firstOrFail();
        $this->assertSame('free', $account->status);
        $this->assertSame('Grátis', $account->plan);
        $this->assertSame('ana@casa.test', $account->email);
        $this->assertSame('carteira-'.$wallet->organization_id, $account->external_ref);

        // Sem confirmar o e-mail, o app espera a confirmação.
        $this->get($this->url('/app'))->assertRedirect($this->url('/verificar-email'));

        // O e-mail fala do Finanças e o link leva ao endereço do Finanças.
        $link = null;
        Notification::assertSentTo($user, VerifyEmail::class, function ($n) use ($user, &$link) {
            $mail = $n->toMail($user);
            $link = $mail->actionUrl;

            return str_contains($mail->subject, 'Foxy Finanças');
        });
        $this->assertStringStartsWith($this->url('/verificar-email/'), $link);

        $this->get($link)->assertRedirect($this->url('/app'));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->get($this->url('/app'))->assertOk()->assertSee('Pessoal')->assertSee('Pessoas na carteira');
    }

    public function test_cadastro_com_negocio_cria_as_duas_carteiras_e_entra_na_pessoal(): void
    {
        config(['finance.signup_open' => true]);
        Notification::fake();

        $this->post($this->url('/cadastro'), $this->signupData(['has_business' => '1', 'business_name' => 'Ateliê da Ana', 'is_mei' => '1']));
        $user = User::where('email', 'ana@casa.test')->firstOrFail();
        $user->markEmailAsVerified();
        $this->actingAs($user->fresh());

        $wallets = Wallet::withoutGlobalScopes()->get()->keyBy('kind');
        $this->assertCount(2, $wallets);
        $this->assertSame('Ateliê da Ana', $wallets['negocio']->name);
        $this->assertTrue($wallets['negocio']->is_mei);
        $this->assertFalse($wallets['pessoal']->is_mei);
        $this->assertSame(2, ProductAccount::where('integration_id', Finance::integration()->id)->count());

        // Entra na pessoal; o menu mostra as duas para trocar.
        $this->get($this->url('/app'))->assertOk()->assertSee('Pessoal / casa')->assertSee('Ateliê da Ana');

        $this->post($this->url('/app/carteiras'), ['organization_id' => $wallets['negocio']->organization_id])->assertRedirect($this->url('/app'));
        $this->get($this->url('/app'))->assertOk()->assertSee('Negócio (MEI ou com sócios)')->assertSee('convide o sócio');
    }

    public function test_cadastro_com_negocio_exige_o_nome(): void
    {
        config(['finance.signup_open' => true]);

        $this->post($this->url('/cadastro'), $this->signupData(['has_business' => '1']))->assertSessionHasErrors('business_name');
        $this->assertSame(0, User::count());
    }

    public function test_sem_escolha_entra_na_carteira_pessoal_mesmo_tendo_varias(): void
    {
        [$ana, $personal] = $this->owner();
        $business = app(OpenWallet::class)->open($ana, 'Ateliê', Wallet::BUSINESS);

        $this->actingAs($ana)->get($this->url('/app'))->assertOk();
        $this->assertSame($personal->organization_id, session('finance.organization_id'));
        $this->assertNotSame($business->organization_id, session('finance.organization_id'));
    }

    public function test_nao_entra_na_carteira_de_outra_pessoa(): void
    {
        [$ana] = $this->owner();
        [, $bia] = $this->owner('Pessoal da Bia', 'bia@casa.test');

        $this->actingAs($ana)->post($this->url('/app/carteiras'), ['organization_id' => $bia->organization_id])->assertNotFound();

        // Nem forçando a sessão: a carteira só vale com vínculo ativo.
        $this->actingAs($ana)->withSession(['finance.organization_id' => $bia->organization_id])->get($this->url('/app'))->assertOk()->assertDontSee('Pessoal da Bia');
    }

    public function test_loja_do_commerce_nao_aparece_como_carteira(): void
    {
        $user = User::factory()->create();
        app(OpenStore::class)->open($user, 'Moda da Ana');

        $this->actingAs($user)->get($this->url('/app'))->assertRedirect($this->url('/app/nova-carteira'));
        $this->get($this->url('/app/nova-carteira'))->assertOk()->assertSee('Peça um convite');
        $this->post($this->url('/app/nova-carteira'), ['name' => 'Casa', 'kind' => 'pessoal'])->assertNotFound();
    }

    public function test_quem_ja_tem_carteira_abre_outra_mesmo_com_cadastro_fechado(): void
    {
        [$ana] = $this->owner();

        $this->actingAs($ana)->post($this->url('/app/nova-carteira'), ['name' => 'Ateliê da Ana', 'kind' => 'negocio', 'is_mei' => '1'])
            ->assertRedirect($this->url('/app'));

        $wallet = Wallet::withoutGlobalScopes()->where('name', 'Ateliê da Ana')->firstOrFail();
        $this->assertTrue($wallet->is_mei);
        $this->assertSame($wallet->organization_id, session('finance.organization_id'));

        config(['finance.max_owned_wallets' => 2]);
        $this->post($this->url('/app/nova-carteira'), ['name' => 'Mais uma', 'kind' => 'pessoal'])->assertSessionHasErrors('name');
    }

    public function test_convite_para_o_socio_cria_o_login_e_entra_na_carteira_do_negocio(): void
    {
        [$ana, $business] = $this->owner('Ateliê da Ana', 'ana@casa.test', Wallet::BUSINESS);
        $token = $this->invite($ana, 'Bruno@Socio.test', 'member');

        $this->get($this->url('/convite/'.$token))->assertOk()->assertSee('Ateliê da Ana')->assertSee('Membro');

        $this->post($this->url('/convite/'.$token), ['name' => 'Bruno', 'password' => 'senha-forte-1', 'password_confirmation' => 'senha-forte-1'])
            ->assertRedirect($this->url('/app'));

        $bruno = User::where('email', 'bruno@socio.test')->firstOrFail();
        $this->assertAuthenticatedAs($bruno);
        $this->assertTrue($bruno->hasVerifiedEmail());
        $this->assertSame('member', Membership::where('user_id', $bruno->id)->value('role'));
        $this->assertNotNull(Invitation::first()->accepted_at);
        $this->assertSame(2, ProductAccount::where('external_ref', 'carteira-'.$business->organization_id)->value('usage')['pessoas na carteira']);

        // Membro vê as pessoas, mas não convida nem mexe na carteira.
        $this->get($this->url('/app/pessoas'))->assertOk()->assertSee('Bruno');
        $this->post($this->url('/app/pessoas/convites'), ['email' => 'x@x.test', 'role' => 'member'])->assertForbidden();
        $this->get($this->url('/app/carteira'))->assertForbidden();

        // O convite usado não vale de novo.
        auth()->logout();
        $this->get($this->url('/convite/'.$token))->assertOk()->assertSee('Convite indisponível');
    }

    public function test_quem_ja_tem_login_de_outro_produto_entra_com_a_mesma_senha(): void
    {
        [$ana] = $this->owner();
        $existing = User::factory()->create(['email' => 'carla@loja.test']);
        app(OpenStore::class)->open($existing, 'Loja da Carla');

        $token = $this->invite($ana, 'carla@loja.test', 'viewer');
        $this->post($this->url('/convite/'.$token))->assertRedirect($this->url('/entrar'));

        $this->actingAs($existing)->post($this->url('/convite/'.$token))->assertRedirect($this->url('/app'));
        $this->assertSame('viewer', Membership::where('user_id', $existing->id)->whereHas('organization', fn ($q) => $q->where('integration_id', Finance::integration()->id))->value('role'));
    }

    public function test_convite_de_um_produto_nao_vale_no_outro(): void
    {
        [$ana, $wallet] = $this->owner();
        [, $token] = Invitation::issue($wallet->organization, 'bia@casa.test', 'member', $ana, 7);

        $store = app(OpenStore::class)->open(User::factory()->create(), 'Moda');
        [, $storeToken] = Invitation::issue($store->organization, 'bia@casa.test', 'attendant', $ana, 7);

        $this->get('http://'.config('foxy.hosts.commerce').'/convite/'.$token)->assertOk()->assertSee('Convite indisponível');
        $this->post('http://'.config('foxy.hosts.commerce').'/convite/'.$token, ['name' => 'Bia', 'password' => 'senha-forte-1', 'password_confirmation' => 'senha-forte-1'])->assertNotFound();
        $this->get($this->url('/convite/'.$storeToken))->assertOk()->assertSee('Convite indisponível');
        $this->post($this->url('/convite/'.$storeToken), ['name' => 'Bia', 'password' => 'senha-forte-1', 'password_confirmation' => 'senha-forte-1'])->assertNotFound();
        $this->assertSame(0, User::where('email', 'bia@casa.test')->count());
    }

    public function test_limite_de_pessoas_conta_os_convites_pendentes(): void
    {
        config(['finance.max_people' => 2]);
        [$ana] = $this->owner();
        $this->invite($ana, 'bia@casa.test', 'member');

        $this->actingAs($ana)->post($this->url('/app/pessoas/convites'), ['email' => 'caio@casa.test', 'role' => 'member'])->assertSessionHasErrors('email');
        // Reenviar para quem já foi convidado continua valendo.
        $this->post($this->url('/app/pessoas/convites'), ['email' => 'bia@casa.test', 'role' => 'viewer'])->assertSessionHasNoErrors();
    }

    public function test_dono_muda_papel_suspende_e_retira_sem_deixar_a_carteira_sem_dono(): void
    {
        [$ana, $wallet] = $this->owner();
        $bia = $this->member($wallet, 'member', 'bia@casa.test');
        $biaMembership = Membership::where('user_id', $bia->id)->firstOrFail();
        $anaMembership = Membership::where('user_id', $ana->id)->firstOrFail();

        $this->actingAs($ana)->post($this->url("/app/pessoas/{$anaMembership->id}/papel"), ['role' => 'member'])->assertSessionHasErrors('role');
        $this->post($this->url("/app/pessoas/{$biaMembership->id}/papel"), ['role' => 'viewer'])->assertSessionHasNoErrors();
        $this->assertSame('viewer', $biaMembership->fresh()->role);

        $this->post($this->url("/app/pessoas/{$biaMembership->id}/suspender"), ['suspend' => 1]);
        $this->assertSame(Membership::SUSPENDED, $biaMembership->fresh()->status);
        $this->actingAs($bia)->get($this->url('/app'))->assertRedirect($this->url('/app/nova-carteira'));

        $this->actingAs($ana)->post($this->url("/app/pessoas/{$biaMembership->id}/retirar"));
        $this->assertSame(Membership::REMOVED, $biaMembership->fresh()->status);
    }

    public function test_quem_so_ve_nao_muda_nada(): void
    {
        [, $wallet] = $this->owner();
        $viewer = $this->member($wallet, 'viewer', 'contador@x.test');

        $this->actingAs($viewer)->get($this->url('/app'))->assertOk()->assertSee('Só vê');
        $this->get($this->url('/app/pessoas'))->assertOk()->assertDontSee('Enviar convite');
        $this->post($this->url('/app/carteira'), ['name' => 'X', 'kind' => 'pessoal'])->assertForbidden();
    }

    public function test_dono_ajusta_a_carteira_e_o_admin_acompanha(): void
    {
        [$ana, $wallet] = $this->owner();

        $this->actingAs($ana)->get($this->url('/app/carteira'))->assertOk()->assertSee('Dia de pagar o DAS');
        $this->post($this->url('/app/carteira'), ['name' => 'Ateliê', 'kind' => 'negocio', 'is_mei' => '1'])->assertSessionHasNoErrors();

        $wallet->refresh();
        $this->assertSame('Ateliê', $wallet->name);
        $this->assertTrue($wallet->is_mei);
        $this->assertSame(20, $wallet->das_day);
        $this->assertSame('Ateliê', ProductAccount::where('external_ref', 'carteira-'.$wallet->organization_id)->value('name'));

        // Voltar a pessoal desliga o MEI.
        $this->post($this->url('/app/carteira'), ['name' => 'Casa', 'kind' => 'pessoal', 'is_mei' => '1']);
        $this->assertFalse($wallet->fresh()->is_mei);
        $this->assertNull($wallet->fresh()->das_day);
    }

    public function test_lista_de_pessoas_exporta_planilha_e_pdf(): void
    {
        [$ana, $wallet] = $this->owner('Casa');
        $this->member($wallet, 'member', 'bia@casa.test');

        $csv = $this->actingAs($ana)->get($this->url('/app/pessoas?exportar=planilha'));
        $csv->assertOk();
        $content = $csv->streamedContent();
        $this->assertStringContainsString('bia@casa.test', $content);
        $this->assertStringContainsString('Dono(a)', $content);

        $this->get($this->url('/app/pessoas?exportar=pdf'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_esqueci_a_senha_manda_o_link_do_financas(): void
    {
        Notification::fake();
        [$ana] = $this->owner();

        $this->post($this->url('/senha'), ['email' => 'ana@casa.test'])->assertSessionHas('status');

        Notification::assertSentTo($ana, ResetPassword::class, function ($n) use ($ana) {
            $mail = $n->toMail($ana);

            return str_starts_with($mail->actionUrl, $this->url('/senha/')) && str_contains($mail->subject, 'Foxy Finanças');
        });
    }

    public function test_perfil_mostra_as_carteiras_e_troca_o_nome(): void
    {
        [$ana] = $this->owner();
        app(OpenWallet::class)->open($ana, 'Ateliê', Wallet::BUSINESS);

        $this->actingAs($ana)->get($this->url('/app/perfil'))->assertOk()->assertSee('Ateliê')->assertSee('Suas carteiras');
        $this->post($this->url('/app/perfil'), ['name' => 'Ana Souza', 'email' => 'ana@casa.test'])->assertSessionHasNoErrors();
        $this->assertSame('Ana Souza', $ana->fresh()->name);
    }
}
