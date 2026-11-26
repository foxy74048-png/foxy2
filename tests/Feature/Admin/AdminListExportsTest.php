<?php

namespace Tests\Feature\Admin;

use App\Ai\Insights\AiInsight;
use App\Ai\Promotions\AiPromotion;
use App\Models\User;
use App\Platform\Announcements\Announcement;
use App\Platform\Organizations\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Ai\InteractsWithFoxyAi;
use Tests\TestCase;

/**
 * Toda lista do Foxy Admin exporta planilha e PDF com os mesmos filtros da tela (Suporte, Promoções de IA,
 * Comunicados, Ideias e dúvidas; Contas e Métricas têm teste próprio).
 */
class AdminListExportsTest extends TestCase
{
    use InteractsWithFoxyAi, RefreshDatabase;

    public function test_support_promotions_announcements_and_insights_export(): void
    {
        $this->setUpFakeAi();
        [$integration, $key] = $this->integrationWithKey('Foxy Studio');
        $this->withToken($key)->postJson('/api/v1/product-snapshot', [
            'metrics' => [],
            'accounts' => [['external_ref' => 'ws-1', 'name' => 'Studio Lumi', 'status' => 'active', 'mrr' => 59.9]],
            'tickets' => [
                ['id' => '1', 'account_ref' => 'ws-1', 'subject' => 'Não agenda', 'status' => 'open', 'message' => 'Socorro'],
                ['id' => '2', 'account_ref' => 'ws-1', 'subject' => 'Já resolvido', 'status' => 'closed', 'message' => 'ok'],
            ],
        ])->assertOk();
        AiPromotion::create(['name' => 'Black Friday', 'audience' => 'todas', 'recurrence' => 'uma_vez', 'credits' => 100, 'status' => 'ativa']);
        Announcement::create(['title' => 'Novidade de dezembro', 'channel' => 'email', 'subject' => 'Oi', 'body' => 'Texto', 'audience' => [], 'status' => 'rascunho']);
        $org = Organization::create(['integration_id' => $integration->id, 'name' => 'Org', 'external_ref' => 'ws-1']);
        AiInsight::create(['organization_id' => $org->id, 'kind' => 'ideia', 'summary' => 'Lembrete por SMS', 'status' => 'nova']);
        AiInsight::create(['organization_id' => $org->id, 'kind' => 'duvida', 'summary' => 'Como emitir nota?', 'status' => 'nova']);

        $this->actingAs(User::factory()->staff()->create());

        $csv = fn (string $url) => $this->get($url)->assertOk()->streamedContent();

        $support = $csv('/admin/suporte?exportar=planilha');
        $this->assertStringContainsString('Não agenda', $support);
        $this->assertStringContainsString('Studio Lumi', $support);
        $this->assertStringNotContainsString('Já resolvido', $support, 'mesmo filtro da tela: só os abertos');
        $this->assertStringContainsString('Já resolvido', $csv('/admin/suporte?situacao=todos&exportar=planilha'));

        $this->assertStringContainsString('Black Friday', $csv('/admin/promocoes?exportar=planilha'));
        $this->assertStringContainsString('Novidade de dezembro', $csv('/admin/comunicados?exportar=planilha'));

        $ideas = $csv('/admin/ideias?exportar=planilha');
        $this->assertStringContainsString('Lembrete por SMS', $ideas);
        $this->assertStringContainsString('Studio Lumi', $ideas);
        $this->assertStringNotContainsString('Como emitir nota?', $ideas);

        foreach (['/admin/suporte', '/admin/promocoes', '/admin/comunicados', '/admin/ideias'] as $url) {
            $this->get($url)->assertOk()->assertSee('exportar=planilha', false);
            $this->get($url.'?exportar=pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        }
    }
}
