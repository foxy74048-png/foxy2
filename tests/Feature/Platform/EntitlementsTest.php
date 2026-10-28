<?php

namespace Tests\Feature\Platform;

use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Entitlements\Entitlements;
use App\Platform\Organizations\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntitlementsTest extends TestCase
{
    use RefreshDatabase;

    private function as(Organization $organization): Entitlements
    {
        app(ContextStore::class)->set(ExecutionContext::forSystem($organization));

        return app(Entitlements::class);
    }

    public function test_records_usage_per_organization_and_per_subject(): void
    {
        $entitlements = $this->as(Organization::create(['name' => 'A']));

        $entitlements->recordUsage('ai.messages', 'user-1', 1, 0.0125);
        $entitlements->recordUsage('ai.messages', 'user-1', 1, 0.0100);
        $entitlements->recordUsage('ai.messages', 'user-2', 1, 0.0050);

        $this->assertSame(['quantity' => 3, 'cost_usd' => 0.0275], $entitlements->usage('ai.messages'));
        $this->assertSame(2, $entitlements->usage('ai.messages', 'user-1')['quantity']);
        $this->assertSame(1, $entitlements->usage('ai.messages', 'user-2')['quantity']);
    }

    public function test_organization_monthly_limit_blocks_usage(): void
    {
        $entitlements = $this->as(Organization::create(['name' => 'A', 'limits' => ['ai.messages.monthly' => 2]]));

        $entitlements->recordUsage('ai.messages', 'user-1');
        $this->assertTrue($entitlements->canUse('ai.messages', 'user-2'));

        $entitlements->recordUsage('ai.messages', 'user-1');
        $this->assertFalse($entitlements->canUse('ai.messages', 'user-2'));
    }

    public function test_per_subject_limit_blocks_only_that_subject(): void
    {
        $entitlements = $this->as(Organization::create(['name' => 'A', 'limits' => ['ai.messages.monthly_per_subject' => 1]]));

        $entitlements->recordUsage('ai.messages', 'user-1');

        $this->assertFalse($entitlements->canUse('ai.messages', 'user-1'));
        $this->assertTrue($entitlements->canUse('ai.messages', 'user-2'));
    }

    public function test_limits_reset_in_a_new_month(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 31));
        $entitlements = $this->as(Organization::create(['name' => 'A', 'limits' => ['ai.messages.monthly' => 1]]));
        $entitlements->recordUsage('ai.messages');
        $this->assertFalse($entitlements->canUse('ai.messages'));

        $this->travelTo(now()->setDate(2026, 11, 1));
        $this->assertTrue($entitlements->canUse('ai.messages'));
    }

    public function test_usage_is_isolated_between_organizations(): void
    {
        $orgA = Organization::create(['name' => 'A']);
        $orgB = Organization::create(['name' => 'B']);

        $this->as($orgA)->recordUsage('ai.messages', null, 5);

        $this->assertSame(0, $this->as($orgB)->usage('ai.messages')['quantity']);
    }

    public function test_default_limits_come_from_config(): void
    {
        config(['foxy.limits' => ['ai.messages.monthly' => 1, 'ai.messages.monthly_per_subject' => null]]);
        $org = Organization::create(['name' => 'A']);

        $this->assertSame(1, $org->limit('ai.messages.monthly'));
        $this->assertNull($org->limit('ai.messages.monthly_per_subject'));

        $custom = Organization::create(['name' => 'B', 'limits' => ['ai.messages.monthly' => 9]]);
        $this->assertSame(9, $custom->limit('ai.messages.monthly'));
    }

    public function test_shipped_config_defines_default_ai_limits(): void
    {
        $org = Organization::create(['name' => 'A']);

        $this->assertSame(2000, $org->limit('ai.messages.monthly'));
        $this->assertSame(200, $org->limit('ai.messages.monthly_per_subject'));
    }
}
