<?php

namespace Tests\Feature\Commerce;

use App\Commerce\Stores\OpenStore;
use App\Commerce\Stores\Store;
use App\Commerce\Team\AccountChangedNotification;
use App\Models\User;
use App\Platform\Memberships\Membership;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Meu perfil: nome, e-mail (com senha atual e nova confirmação) e senha, para qualquer pessoa da equipe. */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['name' => 'Fran', 'email' => 'fran@loja.test', 'password' => 'senha-antiga-1']);
        $this->store = app(OpenStore::class)->open($this->owner, 'Fran Lingerie');
        $this->actingAs($this->owner);
    }

    private function url(string $path): string
    {
        return 'http://'.config('foxy.hosts.commerce').$path;
    }

    public function test_ve_e_edita_o_nome_sem_pedir_senha(): void
    {
        $this->get($this->url('/app'))->assertSee('Meu perfil');
        $this->get($this->url('/app/perfil'))->assertOk()->assertSee('fran@loja.test')->assertSee('Fran Lingerie')->assertSee('Dono(a)');

        $this->post($this->url('/app/perfil'), ['name' => 'Franciele Lima', 'email' => 'FRAN@loja.test'])->assertSessionHasNoErrors()->assertSessionHas('status', 'Perfil salvo.');
        $this->assertSame(['Franciele Lima', 'fran@loja.test'], [$this->owner->fresh()->name, $this->owner->fresh()->email]);
        $this->assertNotNull($this->owner->fresh()->email_verified_at);
    }

    public function test_trocar_email_pede_a_senha_confirma_o_novo_e_avisa_o_antigo(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'ocupado@loja.test']);

        $this->post($this->url('/app/perfil'), ['name' => 'Fran', 'email' => 'nova@loja.test'])->assertSessionHasErrors('email_password');
        $this->post($this->url('/app/perfil'), ['name' => 'Fran', 'email' => 'nova@loja.test', 'email_password' => 'errada'])->assertSessionHasErrors(['email_password' => 'A senha atual não confere.']);
        $this->post($this->url('/app/perfil'), ['name' => 'Fran', 'email' => 'ocupado@loja.test', 'email_password' => 'senha-antiga-1'])->assertSessionHasErrors('email');
        $this->assertSame('fran@loja.test', $this->owner->fresh()->email);

        $this->post($this->url('/app/perfil'), ['name' => 'Fran', 'email' => 'Nova@Loja.test', 'email_password' => 'senha-antiga-1'])
            ->assertRedirect($this->url('/verificar-email'));
        $user = $this->owner->fresh();
        $this->assertSame('nova@loja.test', $user->email);
        $this->assertNull($user->email_verified_at); // até confirmar, o app pede a confirmação
        Notification::assertSentTo($user, VerifyEmail::class);
        Notification::assertSentTo(new AnonymousNotifiable, AccountChangedNotification::class, fn ($n, $c, $to) => $to->routes['mail'] === 'fran@loja.test' && $n->newEmail === 'nova@loja.test');
        $this->get($this->url('/app'))->assertRedirect($this->url('/verificar-email'));
    }

    public function test_trocar_a_senha_e_atendente_tambem_tem_perfil(): void
    {
        Notification::fake();
        $this->post($this->url('/app/perfil/senha'), ['current_password' => 'errada', 'password' => 'nova-senha-123', 'password_confirmation' => 'nova-senha-123'])->assertSessionHasErrors('current_password');
        $this->post($this->url('/app/perfil/senha'), ['current_password' => 'senha-antiga-1', 'password' => 'curta', 'password_confirmation' => 'curta'])->assertSessionHasErrors('password');
        $this->post($this->url('/app/perfil/senha'), ['current_password' => 'senha-antiga-1', 'password' => 'nova-senha-123', 'password_confirmation' => 'nova-senha-123'])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('nova-senha-123', $this->owner->fresh()->password));
        Notification::assertSentTo($this->owner, AccountChangedNotification::class, fn ($n) => $n->what === 'password');

        $attendant = User::factory()->create(['name' => 'Ajudante']);
        Membership::create(['organization_id' => $this->store->organization_id, 'user_id' => $attendant->id, 'role' => 'attendant']);
        $this->actingAs($attendant)->get($this->url('/app/perfil'))->assertOk()->assertSee('Atendente')->assertSee('Fran Lingerie');
        $this->post($this->url('/app/perfil'), ['name' => 'Ajudante da Fran', 'email' => $attendant->email])->assertSessionHasNoErrors();
        $this->assertSame('Ajudante da Fran', $attendant->fresh()->name);
        $this->assertSame('Fran', $this->owner->fresh()->name); // só mexe na própria conta
    }
}
