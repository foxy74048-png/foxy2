<?php

namespace Tests\Feature\Commerce;

use App\Commerce\Stores\OpenStore;
use App\Commerce\Stores\Store;
use App\Commerce\Support\SupportReplyNotification;
use App\Models\User;
use App\Platform\Products\ProductCommand;
use App\Platform\Products\ProductTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Suporte do Commerce na central do Foxy Admin: a loja abre, a equipe Foxy responde, a loja recebe.
 */
class SupportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['name' => 'Ana', 'email' => 'ana@loja.test']);
        $this->store = app(OpenStore::class)->open($this->owner, 'Moda da Ana');
    }

    private function url(string $path): string
    {
        return 'http://'.config('foxy.hosts.commerce').$path;
    }

    public function test_chamado_vai_ao_admin_e_a_resposta_volta_para_a_loja(): void
    {
        Notification::fake();
        $this->actingAs($this->owner)->post($this->url('/app/suporte'), ['subject' => 'Anúncio não publica', 'category' => 'marketplace', 'message' => 'Dá erro de categoria.'])
            ->assertSessionHasNoErrors();

        $ticket = ProductTicket::sole();
        $this->assertSame('open', $ticket->status);
        $this->assertStringContainsString('ana@loja.test', $ticket->message);
        $this->assertSame('Moda da Ana', $ticket->account->name);

        // Equipe Foxy responde pela central de suporte do Admin.
        $staff = User::factory()->staff()->create();
        $this->actingAs($staff)->get('/admin/suporte')->assertOk()->assertSee('Anúncio não publica');
        $this->actingAs($staff)->post("/admin/suporte/{$ticket->id}", ['message' => 'Informe a categoria MLB31447 no anúncio.', 'status' => 'answered'])->assertSessionHasNoErrors();

        $this->artisan('commerce:comandos')->assertSuccessful();
        $this->assertSame('feito', ProductCommand::sole()->status);
        $this->assertSame('answered', $ticket->fresh()->status);
        Notification::assertSentTo($this->owner, SupportReplyNotification::class);

        $this->actingAs($this->owner)->get($this->url('/app/suporte'))->assertOk()->assertSee('Informe a categoria MLB31447')->assertSee('Respondido');
    }

    public function test_loja_so_ve_os_proprios_chamados(): void
    {
        $this->actingAs($this->owner)->post($this->url('/app/suporte'), ['subject' => 'Dúvida da Ana', 'category' => 'duvida', 'message' => 'Oi']);

        $bia = User::factory()->create();
        app(OpenStore::class)->open($bia, 'Loja da Bia');
        $this->actingAs($bia)->get($this->url('/app/suporte'))->assertOk()->assertDontSee('Dúvida da Ana');
    }
}
