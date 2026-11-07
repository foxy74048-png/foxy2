<?php

namespace Tests\Feature\Admin;

use App\Ai\Insights\AiInsight;
use App\Models\User;
use App\Platform\Announcements\Announcement;
use App\Platform\Announcements\AnnouncementDelivery;
use App\Platform\Announcements\AnnouncementService;
use App\Platform\Products\ProductAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Ai\InteractsWithFoxyAi;
use Tests\TestCase;

/**
 * Fase 2 do Foxy Admin (docs/08): comunicados por e-mail/WhatsApp com descadastro e ideias/dúvidas da raposa.
 */
class AnnouncementsAndInsightsTest extends TestCase
{
    use InteractsWithFoxyAi, RefreshDatabase;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakeAi();
        [, $this->key] = $this->integrationWithKey('Foxy Studio');
        config([
            'mail.default' => 'array',
            'foxy.messaging.whatsapp' => ['api_url' => 'https://whats.exemplo.test', 'api_key' => 'k', 'instance' => 'foxy_tenant_1'],
        ]);
        Http::fake(['whats.exemplo.test/*' => Http::response(['key' => ['id' => 'x']], 201)]);
        $this->actingAs(User::factory()->staff()->create(['email' => 'equipe@foxy.test']));
    }

    private function snapshot(array $accounts)
    {
        return $this->withToken($this->key)->postJson('/api/v1/product-snapshot', ['metrics' => [], 'accounts' => $accounts])->assertOk();
    }

    private function account(string $ref, string $status = 'active', array $contact = []): array
    {
        return array_merge(['external_ref' => $ref, 'name' => 'Studio '.$ref, 'plan' => 'Crescimento', 'status' => $status, 'contact_name' => 'Ana '.$ref, 'email' => "$ref@exemplo.test", 'whatsapp' => '11999990000'], $contact);
    }

    private function mails(): array
    {
        return iterator_to_array(app('mailer')->getSymfonyTransport()->messages());
    }

    private function draft(array $overrides = []): Announcement
    {
        $this->post('/admin/comunicados', array_merge(['title' => 'Novidade', 'channel' => 'ambos', 'subject' => 'Oi {nome}', 'body' => "Olá, {nome}! A raposa chegou ao {produto}.", 'statuses' => ['active']], $overrides))->assertRedirect();

        return Announcement::latest()->first();
    }

    public function test_snapshot_keeps_contact_and_never_undoes_an_opt_out(): void
    {
        $this->snapshot([$this->account('ws-1')]);
        ProductAccount::where('external_ref', 'ws-1')->update(['email_opt_out_at' => now()]);

        $this->snapshot([$this->account('ws-1', 'active', ['email' => 'novo@exemplo.test'])]);

        $account = ProductAccount::where('external_ref', 'ws-1')->first();
        $this->assertSame('novo@exemplo.test', $account->email);
        $this->assertNotNull($account->email_opt_out_at, 'o retrato do produto não reinscreve ninguém');
    }

    public function test_draft_shows_audience_preview_and_sends_a_test(): void
    {
        $this->snapshot([$this->account('ws-1'), $this->account('ws-2', 'trial'), $this->account('ws-3', 'active', ['email' => null, 'whatsapp' => null])]);
        $announcement = $this->draft();

        $this->get("/admin/comunicados/{$announcement->id}")->assertOk()
            ->assertSee('2 conta(s)')
            ->assertSee('Olá, Ana ws-1! A raposa chegou ao Foxy Studio.');

        $this->post("/admin/comunicados/{$announcement->id}/teste", ['test_email' => 'equipe@foxy.test', 'test_whatsapp' => '11988887777'])->assertSessionHas('status');
        $this->assertCount(1, $this->mails());
        $this->assertStringStartsWith('[TESTE]', $this->mails()[0]->getOriginalMessage()->getSubject());
        Http::assertSent(fn ($r) => str_contains($r->url(), '/message/sendText/foxy_tenant_1') && $r['number'] === '5511988887777');
        $this->assertSame(0, AnnouncementDelivery::count(), 'teste não entra no histórico');
    }

    public function test_sending_goes_out_in_batches_with_unsubscribe_link_and_skips_who_left(): void
    {
        $this->snapshot([$this->account('ws-1'), $this->account('ws-2'), $this->account('ws-3', 'canceled')]);
        ProductAccount::where('external_ref', 'ws-2')->update(['whatsapp_opt_out_at' => now()]);
        $announcement = $this->draft();

        $this->post("/admin/comunicados/{$announcement->id}/enviar")->assertRedirect();
        $this->assertSame('enviando', $announcement->fresh()->status);
        $this->assertSame(['email' => 2, 'whatsapp' => 1], AnnouncementDelivery::get()->countBy('channel')->all());

        app(AnnouncementService::class)->processBatch(pause: false);

        $this->assertSame(3, AnnouncementDelivery::where('status', 'enviado')->count());
        $this->assertSame('enviado', $announcement->fresh()->status);
        $this->assertCount(2, $this->mails());
        $this->assertStringContainsString('/comunicados/sair/', $this->mails()[0]->getOriginalMessage()->getHtmlBody());
        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', 'Não quer receber comunicados por WhatsApp?'));
    }

    public function test_unsubscribe_needs_the_signed_link_and_a_confirmation(): void
    {
        $this->snapshot([$this->account('ws-1')]);
        $announcement = $this->draft(['channel' => 'email']);
        app(AnnouncementService::class)->start($announcement);
        $delivery = AnnouncementDelivery::first();
        $url = URL::signedRoute('announcements.unsubscribe', ['delivery' => $delivery->id]);

        auth()->logout();
        $this->get("/comunicados/sair/{$delivery->id}")->assertForbidden();
        $this->get($url)->assertOk()->assertSee('Não quer receber comunicados por e-mail?');
        $this->assertNull($delivery->account->fresh()->email_opt_out_at, 'abrir o link não descadastra');

        $this->post($url)->assertOk()->assertSee('Pronto');
        $this->assertNotNull($delivery->account->fresh()->email_opt_out_at);

        app(AnnouncementService::class)->processBatch(pause: false);
        $this->assertSame('pulado', $delivery->fresh()->status);
    }

    public function test_fox_ideas_and_doubts_from_any_product_show_up_in_the_admin(): void
    {
        $this->withToken($this->key)->postJson('/api/v1/organizations', ['name' => 'Studio X', 'external_ref' => 'ws-x'])->assertCreated();
        $org = \App\Platform\Organizations\Organization::where('external_ref', 'ws-x')->first();
        $this->snapshot([$this->account('ws-x')]);
        $this->withToken($this->key)->putJson('/api/v1/host-profiles/agenda-demo', ['name' => 'Foxy Studio'])->assertSuccessful();
        $this->withToken($this->key)->withHeaders(['X-Foxy-Organization' => $org->id])->putJson('/api/v1/host-profiles/agenda-demo/knowledge', ['content' => 'Manual'])->assertSuccessful();
        $conversation = $this->withToken($this->key)->withHeaders(['X-Foxy-Organization' => $org->id])->postJson('/api/v1/conversations', ['host_profile' => 'agenda-demo', 'user_ref' => 'u-1'])->json('conversation_id');

        $this->ai->pushToolUse([['id' => 'toolu_1', 'name' => 'foxy_registrar_sugestao', 'input' => ['pedido' => 'Emitir nota fiscal.']]])->pushText('Anotei.');
        $this->withToken($this->key)->withHeaders(['X-Foxy-Organization' => $org->id])->postJson("/api/v1/conversations/{$conversation}/messages", ['text' => 'nota fiscal?', 'page' => 'Financeiro'])->assertOk();

        $this->assertDatabaseHas('ai_insights', ['organization_id' => $org->id, 'kind' => 'ideia', 'summary' => 'Emitir nota fiscal.', 'page' => 'Financeiro']);
        $this->get('/admin/ideias')->assertOk()->assertSee('Emitir nota fiscal.')->assertSee('Na tela: Financeiro')->assertSee('Foxy Studio')->assertSee('Studio ws-x');

        $insight = AiInsight::first();
        $this->post("/admin/ideias/{$insight->id}", ['status' => 'planejada'])->assertRedirect();
        $this->assertSame('planejada', $insight->fresh()->status);
    }
}
