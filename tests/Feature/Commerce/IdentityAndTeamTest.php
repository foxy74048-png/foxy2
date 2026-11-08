<?php

namespace Tests\Feature\Commerce;

use App\Commerce\Commerce;
use App\Commerce\Stores\OpenStore;
use App\Commerce\Stores\Store;
use App\Commerce\Team\InvitationNotification;
use App\Models\User;
use App\Platform\Audit\AuditEntry;
use App\Platform\Memberships\Invitation;
use App\Platform\Memberships\Membership;
use App\Platform\Products\ProductAccount;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Foxy Commerce, fatia 1 (docs/09, seção 11): cada pessoa com o próprio login, várias pessoas por loja,
 * papéis, convites, e a equipe Foxy separada dos clientes.
 */
class IdentityAndTeamTest extends TestCase
{
    use RefreshDatabase;

    private function url(string $path): string
    {
        return 'http://'.config('foxy.hosts.commerce').$path;
    }

    /** @return array{0: User, 1: Store} */
    private function owner(string $store = 'Moda da Ana', string $email = 'ana@loja.test'): array
    {
        $user = User::factory()->create(['name' => 'Ana', 'email' => $email]);

        return [$user, app(OpenStore::class)->open($user, $store)];
    }

    private function member(Store $store, string $role, string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        Membership::create(['organization_id' => $store->organization_id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    /** Convite enviado pela tela e o token do link que chegou no e-mail. */
    private function invite(User $owner, string $email, string $role): string
    {
        Notification::fake();
        $this->actingAs($owner)->post($this->url('/app/equipe/convites'), ['email' => $email, 'role' => $role])->assertSessionHasNoErrors();

        $url = null;
        Notification::assertSentTo(new AnonymousNotifiable, InvitationNotification::class, function ($n, $channels, $notifiable) use ($email, &$url) {
            $url = $n->url;

            return $notifiable->routes['mail'] === $email;
        });
        auth()->logout();

        return basename(parse_url($url, PHP_URL_PATH));
    }

    public function test_clientes_nao_entram_no_console_nem_no_foxy_admin(): void
    {
        $customer = User::factory()->create(['email' => 'cliente@loja.test']);

        $this->actingAs($customer)->get('/admin')->assertForbidden();
        $this->actingAs($customer)->get('/console')->assertForbidden();
        auth()->logout();

        $this->post('/console/login', ['email' => 'cliente@loja.test', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->actingAs(User::factory()->staff()->create())->get('/admin')->assertOk();
    }

    public function test_cadastro_fica_fechado_ate_o_lancamento(): void
    {
        $this->get($this->url('/cadastro'))->assertOk()->assertSee('Cadastro em breve');
        $this->post($this->url('/cadastro'), ['name' => 'X'])->assertNotFound();
        $this->assertSame(0, User::count());
    }

    public function test_cadastro_cria_login_loja_dono_e_conta_no_admin(): void
    {
        config(['commerce.signup_open' => true]);
        Notification::fake();

        $this->post($this->url('/cadastro'), [
            'name' => 'Ana Souza', 'email' => 'Ana@Loja.test', 'password' => 'senha-forte-1', 'password_confirmation' => 'senha-forte-1',
            'store_name' => 'Moda da Ana', 'whatsapp' => '11999990000',
        ])->assertRedirect($this->url('/verificar-email'));

        $user = User::where('email', 'ana@loja.test')->firstOrFail();
        $this->assertFalse($user->isStaff());
        $this->assertAuthenticatedAs($user);

        $store = Store::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('moda-da-ana', $store->slug);
        $this->assertSame(Commerce::integration()->id, $store->organization->integration_id);
        $this->assertSame('owner', Membership::where('user_id', $user->id)->value('role'));

        $account = ProductAccount::where('integration_id', Commerce::integration()->id)->firstOrFail();
        $this->assertSame('Moda da Ana', $account->name);
        $this->assertSame('trial', $account->status);
        $this->assertSame('ana@loja.test', $account->email);
        $this->assertTrue($account->trial_ends_at->isSameDay(now()->addDays(14)));

        // Sem confirmar o e-mail, o app espera a confirmação.
        $this->get($this->url('/app'))->assertRedirect($this->url('/verificar-email'));

        $link = null;
        Notification::assertSentTo($user, VerifyEmail::class, function ($n) use ($user, &$link) {
            $link = $n->toMail($user)->actionUrl;

            return true;
        });
        $this->assertStringStartsWith($this->url('/verificar-email/'), $link);

        $this->get($link)->assertRedirect($this->url('/app'));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->get($this->url('/app'))->assertOk()->assertSee('Moda da Ana');
    }

    public function test_endereco_da_loja_nao_repete_nem_usa_palavra_reservada(): void
    {
        [, $a] = $this->owner('Moda da Ana', 'a@loja.test');
        [, $b] = $this->owner('Moda da Ána', 'b@loja.test');
        [, $c] = $this->owner('App', 'c@loja.test');

        $this->assertSame('moda-da-ana', $a->slug);
        $this->assertSame('moda-da-ana-2', $b->slug);
        $this->assertSame('app-loja', $c->slug);
    }

    public function test_login_e_saida_pelo_endereco_do_commerce(): void
    {
        [$ana] = $this->owner();

        $this->get($this->url('/app'))->assertRedirect($this->url('/entrar'));
        $this->post($this->url('/entrar'), ['email' => 'ana@loja.test', 'password' => 'errada'])->assertSessionHasErrors('email');
        $this->post($this->url('/entrar'), ['email' => 'ana@loja.test', 'password' => 'password'])->assertRedirect($this->url('/app'));
        $this->assertAuthenticatedAs($ana);

        $this->post($this->url('/sair'))->assertRedirect($this->url('/entrar'));
        $this->assertGuest();
    }

    public function test_convite_cria_o_login_do_funcionario_com_o_papel(): void
    {
        [$ana, $store] = $this->owner();
        $token = $this->invite($ana, 'bruno@loja.test', 'attendant');

        $this->get($this->url("/convite/{$token}"))->assertOk()->assertSee('Moda da Ana')->assertSee('Atendente')->assertSee('Crie o seu acesso');
        $this->post($this->url("/convite/{$token}"), ['name' => 'Bruno', 'password' => 'senha-forte-2', 'password_confirmation' => 'senha-forte-2'])
            ->assertRedirect($this->url('/app'));

        $bruno = User::where('email', 'bruno@loja.test')->firstOrFail();
        $this->assertAuthenticatedAs($bruno);
        $this->assertTrue($bruno->hasVerifiedEmail());
        $this->assertTrue(Hash::check('senha-forte-2', $bruno->password));
        $this->assertSame('attendant', Membership::where('user_id', $bruno->id)->where('organization_id', $store->organization_id)->value('role'));
        $this->assertNotNull(Invitation::first()->accepted_at);

        // Convite usado não vale de novo.
        auth()->logout();
        $this->get($this->url("/convite/{$token}"))->assertSee('Convite indisponível');

        // Atendente não vê nem mexe na equipe.
        $this->actingAs($bruno)->get($this->url('/app'))->assertOk()->assertDontSee('Ver equipe');
        $this->actingAs($bruno)->get($this->url('/app/equipe'))->assertForbidden();
        $this->actingAs($bruno)->post($this->url('/app/equipe/convites'), ['email' => 'x@loja.test', 'role' => 'owner'])->assertForbidden();

        $this->assertSame(2, ProductAccount::first()->usage['pessoas na equipe']);
        $this->assertTrue(AuditEntry::withoutGlobalScopes()->where('action', 'commerce.team.joined')->where('principal_type', 'user')->where('principal_id', (string) $bruno->id)->exists());
    }

    public function test_convite_para_quem_ja_tem_login_pede_a_senha_e_confere_o_email(): void
    {
        [$ana] = $this->owner();
        [$carla] = $this->owner('Outra Loja', 'carla@loja.test');
        $token = $this->invite($ana, 'carla@loja.test', 'manager');

        $this->get($this->url("/convite/{$token}"))->assertSee('Você já tem login');
        $this->post($this->url("/convite/{$token}"))->assertRedirect($this->url('/entrar'));
        $this->post($this->url('/entrar'), ['email' => 'carla@loja.test', 'password' => 'password'])->assertRedirect($this->url("/convite/{$token}"));
        $this->post($this->url("/convite/{$token}"))->assertRedirect($this->url('/app'));
        $this->assertSame(2, Membership::active()->where('user_id', $carla->id)->count());

        // Logado com outro e-mail, o convite não é aceito.
        $token = $this->invite($ana, 'diego@loja.test', 'manager');
        $this->actingAs($carla)->from($this->url("/convite/{$token}"))->post($this->url("/convite/{$token}"))->assertSessionHasErrors('email');
        $this->assertFalse(Membership::whereHas('user', fn ($q) => $q->where('email', 'diego@loja.test'))->exists());
    }

    public function test_quem_esta_em_duas_lojas_escolhe_e_nao_entra_em_loja_alheia(): void
    {
        [$ana, $lojaA] = $this->owner();
        [, $lojaB] = $this->owner('Loja da Bia', 'bia@loja.test');
        [, $lojaC] = $this->owner('Loja da Cris', 'cris@loja.test');
        Membership::create(['organization_id' => $lojaB->organization_id, 'user_id' => $ana->id, 'role' => 'manager']);

        $this->actingAs($ana)->get($this->url('/app'))->assertRedirect($this->url('/app/lojas'));
        $this->get($this->url('/app/lojas'))->assertSee('Moda da Ana')->assertSee('Loja da Bia')->assertDontSee('Loja da Cris');

        $this->post($this->url('/app/lojas'), ['organization_id' => $lojaC->organization_id])->assertNotFound();
        $this->post($this->url('/app/lojas'), ['organization_id' => $lojaB->organization_id])->assertRedirect($this->url('/app'));
        $this->get($this->url('/app'))->assertSee('Loja da Bia')->assertSee('Trocar de loja');
    }

    public function test_nao_mexe_na_equipe_de_outra_loja(): void
    {
        [$ana] = $this->owner();
        [, $lojaB] = $this->owner('Loja da Bia', 'bia@loja.test');
        $bia = Membership::where('organization_id', $lojaB->organization_id)->firstOrFail();

        $this->actingAs($ana)->post($this->url("/app/equipe/{$bia->id}/papel"), ['role' => 'attendant'])->assertNotFound();
        $this->actingAs($ana)->post($this->url("/app/equipe/{$bia->id}/remover"))->assertNotFound();
        $this->assertSame('owner', $bia->fresh()->role);
        $this->assertSame(Membership::ACTIVE, $bia->fresh()->status);
    }

    public function test_loja_nunca_fica_sem_dono_e_quem_sai_perde_o_acesso(): void
    {
        [$ana, $store] = $this->owner();
        $own = Membership::where('user_id', $ana->id)->firstOrFail();

        $this->actingAs($ana)->post($this->url("/app/equipe/{$own->id}/papel"), ['role' => 'manager'])->assertSessionHasErrors('role');
        $this->actingAs($ana)->post($this->url("/app/equipe/{$own->id}/remover"))->assertSessionHasErrors('role');
        $this->assertSame('owner', $own->fresh()->role);

        $bruno = $this->member($store, 'manager', 'bruno@loja.test');
        $brunoMembership = Membership::where('user_id', $bruno->id)->firstOrFail();
        $this->actingAs($ana)->post($this->url("/app/equipe/{$brunoMembership->id}/papel"), ['role' => 'owner'])->assertSessionHasNoErrors();
        $this->actingAs($ana)->post($this->url("/app/equipe/{$own->id}/papel"), ['role' => 'manager'])->assertSessionHasNoErrors();
        $this->assertSame('manager', $own->fresh()->role);

        $this->actingAs($bruno)->post($this->url("/app/equipe/{$own->id}/remover"))->assertSessionHasNoErrors();
        $this->actingAs($ana)->get($this->url('/app'))->assertRedirect($this->url('/app/nova-loja'));
        $this->get($this->url('/app/nova-loja'))->assertSee('Peça um convite');
    }

    public function test_gerente_ve_a_equipe_mas_nao_convida(): void
    {
        [, $store] = $this->owner();
        $gerente = $this->member($store, 'manager', 'gerente@loja.test');

        $this->actingAs($gerente)->get($this->url('/app/equipe'))->assertOk()->assertSee('Ana')->assertDontSee('Convidar alguém');
        $this->actingAs($gerente)->post($this->url('/app/equipe/convites'), ['email' => 'x@loja.test', 'role' => 'attendant'])->assertForbidden();
    }

    public function test_nova_senha_pelo_email(): void
    {
        [$ana] = $this->owner();
        Notification::fake();

        $this->post($this->url('/senha'), ['email' => 'ana@loja.test'])->assertSessionHas('status');
        $this->post($this->url('/senha'), ['email' => 'ninguem@loja.test'])->assertSessionHas('status');

        $token = null;
        Notification::assertSentTo($ana, ResetPassword::class, function ($n) use ($ana, &$token) {
            $token = $n->token;
            $this->assertStringStartsWith($this->url('/senha/'), $n->toMail($ana)->actionUrl);

            return true;
        });

        $this->post($this->url('/senha-nova'), ['token' => $token, 'email' => 'ana@loja.test', 'password' => 'nova-senha-9', 'password_confirmation' => 'nova-senha-9'])
            ->assertRedirect($this->url('/entrar'));
        $this->assertTrue(Hash::check('nova-senha-9', $ana->fresh()->password));
    }

    public function test_a_loja_aparece_no_foxy_admin(): void
    {
        $this->owner();

        $this->actingAs(User::factory()->staff()->create())->get('/admin/contas')->assertOk()
            ->assertSee('Moda da Ana')->assertSee('Foxy Commerce');
    }

    public function test_dado_da_loja_so_aparece_no_contexto_dela(): void
    {
        [$ana] = $this->owner();
        $this->owner('Loja da Bia', 'bia@loja.test');

        $this->actingAs($ana)->get($this->url('/app'))->assertOk()->assertSee('Moda da Ana')->assertDontSee('Loja da Bia');
        // Fora de contexto (sem loja), o escopo não devolve nada.
        $this->assertSame(0, Store::count());
        $this->assertSame(2, Store::withoutGlobalScopes()->count());
    }
}
