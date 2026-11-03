<?php

namespace Tests\Feature\Admin;

use App\Ai\Plans\AiCreditGrant;
use App\Models\User;
use App\Platform\Products\ProductAccount;
use App\Platform\Products\ProductCommand;
use App\Platform\Products\ProductTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Ai\InteractsWithFoxyAi;
use Tests\TestCase;

/**
 * Centralizar no Foxy Admin (docs/08, seção 3.1): ficha da conta, ações que o produto executa (comandos),
 * presente de perguntas, mensagem de ajuda e suporte central.
 */
class CentralAdminTest extends TestCase
{
    use InteractsWithFoxyAi, RefreshDatabase;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakeAi();
        [, $this->key] = $this->integrationWithKey('Foxy Studio');
        config(['foxy.messaging.whatsapp' => ['api_url' => 'https://whats.exemplo.test', 'api_key' => 'k', 'instance' => 'foxy_tenant_1']]);
        Http::fake(['whats.exemplo.test/*' => Http::response([], 201)]);
        $this->actingAs(User::factory()->create());
    }

    private function snapshot(array $extra = [])
    {
        return $this->withToken($this->key)->postJson('/api/v1/product-snapshot', array_merge([
            'metrics' => [],
            'accounts' => [[
                'external_ref' => 'foxy-ws-7', 'name' => 'Studio Lumi', 'plan' => 'Clínica', 'status' => 'trial', 'trial_ends_at' => now()->addDays(2)->toIso8601String(),
                'contact_name' => 'Ana', 'email' => 'ana@exemplo.test', 'whatsapp' => '11999990000', 'health' => 'idle',
                'problems' => [['key' => 'whatsapp', 'label' => '📵 WhatsApp não envia', 'detail' => '3 mensagens não saíram.', 'help' => 'Oi, Ana! Seu WhatsApp está desconectado.']],
                'usage' => ['clientes' => 11, 'atendimentos (30 dias)' => 24], 'fox_credits' => 250, 'admin_url' => 'https://studio.foxy.net.br/admin/usuarios/7/editar',
            ]],
            'tickets' => [['id' => '55', 'account_ref' => 'foxy-ws-7', 'subject' => 'Não consigo agendar', 'status' => 'open', 'message' => 'Dá erro ao salvar.', 'opened_at' => now()->toIso8601String()]],
        ], $extra))->assertOk();
    }

    private function account(): ProductAccount
    {
        return ProductAccount::where('external_ref', 'foxy-ws-7')->firstOrFail();
    }

    public function test_account_page_shows_everything_in_one_place(): void
    {
        $this->snapshot();

        $this->get('/admin/contas/'.$this->account()->id)->assertOk()
            ->assertSee('Studio Lumi')->assertSee('Parada')->assertSee('atendimentos (30 dias): 24')
            ->assertSee('📵 WhatsApp não envia')->assertSee('Oi, Ana! Seu WhatsApp está desconectado.')
            ->assertSee('250 por mês')->assertSee('Não consigo agendar')
            ->assertSee('https://studio.foxy.net.br/admin/usuarios/7/editar');

        $this->get('/admin')->assertOk()->assertSee('Contas com problema')->assertSee('Studio Lumi');
    }

    public function test_actions_become_commands_the_product_picks_up_and_answers(): void
    {
        $this->snapshot();
        $account = $this->account();

        $this->post("/admin/contas/{$account->id}/acao", ['type' => 'account.extend_trial', 'days' => 7])->assertRedirect()->assertSessionHas('status');
        $this->post("/admin/contas/{$account->id}/acao", ['type' => 'account.fox_credits', 'credits' => 1000])->assertRedirect();

        $picked = $this->withToken($this->key)->getJson('/api/v1/product-commands')->assertOk()->json('data');
        $this->assertSame(['account.extend_trial', 'account.fox_credits'], array_column($picked, 'type'));
        $this->assertSame(['foxy-ws-7', 7], [$picked[0]['account_ref'], $picked[0]['payload']['days']]);
        $this->assertSame([], $this->withToken($this->key)->getJson('/api/v1/product-commands')->json('data'), 'o mesmo pedido não sai duas vezes');

        $this->withToken($this->key)->postJson("/api/v1/product-commands/{$picked[0]['id']}/result", ['ok' => true, 'message' => 'Teste até 13/11.'])->assertOk();
        $this->withToken($this->key)->postJson("/api/v1/product-commands/{$picked[1]['id']}/result", ['ok' => false, 'message' => 'Conta não encontrada.'])->assertOk();

        $this->assertSame(['feito', 'falhou'], ProductCommand::orderBy('created_at')->pluck('status')->all());
        $this->get("/admin/contas/{$account->id}")->assertSee('Teste até 13/11.')->assertSee('Não deu certo');
    }

    public function test_commands_stay_in_their_own_product(): void
    {
        $this->snapshot();
        $this->post('/admin/contas/'.$this->account()->id.'/acao', ['type' => 'account.unsuspend']);
        [, $other] = $this->integrationWithKey('Foxy Commerce');

        $this->withToken($other)->getJson('/api/v1/product-commands')->assertOk()->assertJsonPath('data', []);
        $id = ProductCommand::first()->id;
        $this->withToken($other)->postJson("/api/v1/product-commands/{$id}/result", ['ok' => true])->assertNotFound();
    }

    public function test_gift_goes_straight_to_the_fox_and_help_goes_by_whatsapp(): void
    {
        $this->snapshot();
        $account = $this->account();

        $this->post("/admin/contas/{$account->id}/presente", ['credits' => 100, 'note' => 'mimo'])->assertRedirect()->assertSessionHas('status');
        $this->assertSame(100, (int) AiCreditGrant::withoutGlobalScopes()->sum('credits'));

        $this->post("/admin/contas/{$account->id}/ajuda", ['message' => 'Oi, Ana! Vamos reconectar?'])->assertRedirect()->assertSessionHas('status');
        Http::assertSent(fn ($r) => $r['number'] === '5511999990000' && $r['text'] === 'Oi, Ana! Vamos reconectar?');
    }

    public function test_support_reply_goes_to_the_product_and_updates_the_ticket(): void
    {
        $this->snapshot();
        $ticket = ProductTicket::firstOrFail();

        $this->get('/admin/suporte')->assertOk()->assertSee('Dá erro ao salvar.')->assertSee('Studio Lumi');
        $this->post("/admin/suporte/{$ticket->id}", ['message' => 'Já corrigimos, tente de novo.', 'status' => 'closed'])->assertRedirect();
        $this->get('/admin/suporte')->assertSee('Resposta a caminho do produto');

        $command = $this->withToken($this->key)->getJson('/api/v1/product-commands')->json('data.0');
        $this->assertSame(['support.reply', '55', 'closed'], [$command['type'], $command['payload']['ticket_id'], $command['payload']['status']]);
        $this->withToken($this->key)->postJson("/api/v1/product-commands/{$command['id']}/result", ['ok' => true])->assertOk();

        $this->assertSame('closed', $ticket->fresh()->status);
    }
}
