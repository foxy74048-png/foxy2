<?php

namespace Tests\Feature\Admin;

use App\Ai\Plans\AiCreditGrant;
use App\Ai\Promotions\AiPromotion;
use App\Ai\Promotions\AiPromotionGrant;
use App\Ai\Promotions\PromotionEngine;
use App\Models\User;
use App\Platform\Organizations\Organization;
use App\Platform\Products\ProductAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Ai\InteractsWithFoxyAi;
use Tests\TestCase;

/**
 * Foxy Admin e vitrine (docs/08): retrato das contas enviado pelo produto, painel, contas e promoções de IA.
 */
class FoxyAdminTest extends TestCase
{
    use InteractsWithFoxyAi, RefreshDatabase;

    private string $key;

    private string $integration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakeAi();
        [$integration, $this->key] = $this->integrationWithKey('Foxy Studio');
        $this->integration = $integration->id;
    }

    private function snapshot(array $accounts, array $metrics = ['mrr' => 59.9])
    {
        return $this->withToken($this->key)->postJson('/api/v1/product-snapshot', ['metrics' => $metrics, 'accounts' => $accounts]);
    }

    private function account(string $ref, string $status = 'active', array $extra = []): array
    {
        return array_merge(['external_ref' => $ref, 'name' => 'Conta '.$ref, 'plan' => 'Crescimento', 'status' => $status, 'mrr' => $status === 'active' ? 59.9 : 0, 'signed_up_at' => now()->subDays(5)->toIso8601String()], $extra);
    }

    private function aiClient(string $ref): Organization
    {
        return Organization::create(['integration_id' => $this->integration, 'name' => 'Conta '.$ref, 'external_ref' => $ref]);
    }

    private function extraCredits(Organization $org): int
    {
        return (int) AiCreditGrant::withoutGlobalScopes()->where('organization_id', $org->id)->where('period', now()->format('Y-m'))->sum('credits');
    }

    public function test_home_depends_on_the_address(): void
    {
        $this->get('http://foxy.net.br/')->assertOk()->assertSee('A Foxy cuida do resto')->assertSee('Foxy Studio')->assertSee('https://studio.foxy.net.br/cadastro');
        $this->get('http://www.foxy.net.br/')->assertOk()->assertSee('Foxy Commerce');
        $this->get('http://admin.foxy.net.br/')->assertRedirect(route('admin.dashboard'));
        $this->get('http://ai.foxy.net.br/')->assertRedirect('/console');
    }

    public function test_admin_is_only_for_the_foxy_team(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create());
        $this->get('/admin')->assertOk()->assertSee('Faturamento mensal');
        $this->get('/admin/contas')->assertOk();
        $this->get('/admin/promocoes')->assertOk()->assertSee('Nova promoção');
    }

    public function test_product_sends_its_accounts_and_missing_ones_become_removed(): void
    {
        $this->snapshot([$this->account('ws-1'), $this->account('ws-2', 'trial')])->assertOk()->assertJsonPath('accounts', 2);
        $this->snapshot([$this->account('ws-1')])->assertOk();

        $this->assertSame('active', ProductAccount::where('external_ref', 'ws-1')->value('status'));
        $this->assertSame('removed', ProductAccount::where('external_ref', 'ws-2')->value('status'));

        $this->actingAs(User::factory()->create());
        $this->get('/admin')->assertOk()->assertSee('R$ 59,90');
        $this->get('/admin/contas')->assertOk()->assertSee('Conta ws-1')->assertDontSee('Conta ws-2');
    }

    public function test_snapshot_needs_the_scope_and_stays_in_its_own_product(): void
    {
        [, $limited] = $this->integrationWithKey('Outro sistema', ['ai.conversations']);
        $this->withToken($limited)->postJson('/api/v1/product-snapshot', ['metrics' => [], 'accounts' => []])->assertForbidden();

        [, $other] = $this->integrationWithKey('Foxy Commerce');
        $this->snapshot([$this->account('ws-1')]);
        $this->withToken($other)->postJson('/api/v1/product-snapshot', ['metrics' => [], 'accounts' => []])->assertOk();

        $this->assertSame('active', ProductAccount::where('external_ref', 'ws-1')->value('status'), 'o retrato de outro produto não mexe nestas contas');
    }

    public function test_new_accounts_promotion_gives_once_and_applies_to_new_clients(): void
    {
        $old = $this->aiClient('ws-velha');
        $new = $this->aiClient('ws-nova');
        $this->snapshot([$this->account('ws-velha', 'active', ['signed_up_at' => now()->subDays(90)->toIso8601String()]), $this->account('ws-nova')]);

        $this->actingAs(User::factory()->create());
        $this->post('/admin/promocoes', ['name' => 'Boas-vindas', 'audience' => 'novas_contas', 'recurrence' => 'uma_vez', 'credits' => 100, 'new_account_days' => 30])
            ->assertRedirect(route('admin.promotions'))->assertSessionHas('status');

        $this->assertSame(100, $this->extraCredits($new));
        $this->assertSame(0, $this->extraCredits($old));

        app(PromotionEngine::class)->apply();
        $this->assertSame(100, $this->extraCredits($new), 'aplicar de novo não repete');

        // Cliente que aparece depois na Foxy AI já ganha na hora.
        $this->snapshot([$this->account('ws-velha'), $this->account('ws-nova'), $this->account('ws-recente')]);
        $this->withToken($this->key)->postJson('/api/v1/organizations', ['name' => 'Conta ws-recente', 'external_ref' => 'ws-recente'])->assertCreated();
        $this->assertSame(100, $this->extraCredits(Organization::where('external_ref', 'ws-recente')->first()));
    }

    public function test_monthly_partner_promotion_renews_every_month_while_running(): void
    {
        $partner = $this->aiClient('ws-parceira');
        $this->aiClient('ws-outra');
        $this->actingAs(User::factory()->create());

        $this->post('/admin/promocoes', ['name' => 'Parceria DA', 'audience' => 'contas', 'account_refs' => ['ws-parceira'], 'recurrence' => 'mensal', 'credits' => 500]);
        $promotion = AiPromotion::first();
        $this->assertSame(500, $this->extraCredits($partner));

        $this->travel(1)->months();
        app(PromotionEngine::class)->apply();
        $this->assertSame(500, $this->extraCredits($partner), 'ganha de novo no mês seguinte');
        $this->assertSame(2, AiPromotionGrant::where('organization_id', $partner->id)->count());

        $this->post("/admin/promocoes/{$promotion->id}/situacao", ['status' => 'pausada']);
        $this->travel(1)->months();
        app(PromotionEngine::class)->apply();
        $this->assertSame(0, $this->extraCredits($partner), 'pausada não entrega');
        $this->assertSame(0, AiPromotionGrant::where('organization_id', '!=', $partner->id)->count());
    }

    public function test_promotion_credits_count_in_the_fox_month(): void
    {
        $org = $this->aiClient('ws-1');
        $this->snapshot([$this->account('ws-1')]);
        AiPromotion::create(['name' => 'Black Friday', 'audience' => 'todas', 'recurrence' => 'uma_vez', 'credits' => 100, 'status' => 'ativa']);

        app(PromotionEngine::class)->apply();

        $this->withToken($this->key)->getJson("/api/v1/organizations/{$org->id}/ai-subscription")->assertOk()->assertJsonPath('credits_extra', 100);
    }

    public function test_trial_and_canceled_follow_the_audience(): void
    {
        $trial = $this->aiClient('ws-teste');
        $canceled = $this->aiClient('ws-cancelada');
        $this->snapshot([$this->account('ws-teste', 'trial'), $this->account('ws-cancelada', 'canceled')]);

        AiPromotion::create(['name' => 'Todas', 'audience' => 'todas', 'recurrence' => 'uma_vez', 'credits' => 50, 'status' => 'ativa']);
        app(PromotionEngine::class)->apply();

        $this->assertSame(50, $this->extraCredits($trial));
        $this->assertSame(0, $this->extraCredits($canceled));
    }
}
