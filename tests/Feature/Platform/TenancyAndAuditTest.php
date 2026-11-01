<?php

namespace Tests\Feature\Platform;

use App\Ai\Usage\UsageRecord;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditEntry;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Integrations\Integration;
use App\Platform\Integrations\IntegrationScope;
use App\Platform\Organizations\Organization;
use App\Platform\Organizations\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class TenancyAndAuditTest extends TestCase
{
    use RefreshDatabase;

    private function organization(string $name, ?Integration $integration = null): Organization
    {
        $integration ??= Integration::create(['name' => "Sistema {$name}"]);

        return Organization::create(['name' => $name, 'integration_id' => $integration->id]);
    }

    private function runAs(Organization $organization, callable $callback): mixed
    {
        $context = ExecutionContext::forApiClient($organization->integration, 'client-x', $organization);

        return app(ContextStore::class)->run($context, $callback);
    }

    private function recordUsage(): UsageRecord
    {
        return UsageRecord::create(['model' => 'x', 'input_tokens' => 1, 'output_tokens' => 1, 'cache_read_tokens' => 0, 'cache_write_tokens' => 0, 'cost_usd' => 0, 'request_id' => 'r']);
    }

    public function test_client_data_is_invisible_to_another_client_of_the_same_system(): void
    {
        $system = Integration::create(['name' => 'Agenda']);
        $orgA = $this->organization('A', $system);
        $orgB = $this->organization('B', $system);

        $this->runAs($orgA, fn () => $this->recordUsage());

        $this->assertSame(1, $this->runAs($orgA, fn () => UsageRecord::count()));
        $this->assertSame(0, $this->runAs($orgB, fn () => UsageRecord::count()));
    }

    public function test_without_context_nothing_is_returned_unless_scope_is_removed_explicitly(): void
    {
        $org = $this->organization('A');
        $this->runAs($org, fn () => [$this->recordUsage(), Audit::record('test.a')]);

        $this->assertSame(0, UsageRecord::count());
        $this->assertSame(0, AuditEntry::count());
        $this->assertSame(1, UsageRecord::withoutGlobalScope(OrganizationScope::class)->count());
        $this->assertSame(1, AuditEntry::withoutGlobalScope(IntegrationScope::class)->count());
    }

    public function test_client_data_requires_an_organization_in_the_context(): void
    {
        $system = Integration::create(['name' => 'Agenda']);
        $this->expectException(LogicException::class);

        app(ContextStore::class)->run(ExecutionContext::forApiClient($system, 'c'), fn () => $this->recordUsage());
    }

    public function test_audit_records_system_client_who_on_behalf_of_whom_and_correlation_id(): void
    {
        $org = $this->organization('A');
        $context = ExecutionContext::forApiClient($org->integration, 'client-1', $org)->asAiAgentFor('user-42');

        $entry = app(ContextStore::class)->run($context, fn () => Audit::record('ai.tool_requested', 'ok', null, ['tool' => 'financeiro_saldos']));

        $this->assertSame($org->integration_id, $entry->integration_id);
        $this->assertSame($org->id, $entry->organization_id);
        $this->assertSame('ai_agent', $entry->principal_type);
        $this->assertSame('client-1', $entry->principal_id);
        $this->assertSame('user-42', $entry->on_behalf_of);
        $this->assertSame($context->correlationId, $entry->correlation_id);
        $this->assertSame(['tool' => 'financeiro_saldos'], $entry->metadata);
    }

    public function test_audit_of_one_system_is_invisible_to_another(): void
    {
        $orgA = $this->organization('A');
        $orgB = $this->organization('B');
        $this->runAs($orgA, fn () => Audit::record('test.a'));

        $this->assertSame(0, $this->runAs($orgB, fn () => AuditEntry::count()));
    }

    public function test_audit_entries_cannot_be_changed_or_deleted(): void
    {
        $entry = $this->runAs($this->organization('A'), fn () => Audit::record('test'));

        try {
            $entry->update(['result' => 'erro']);
            $this->fail('Alteração deveria falhar.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $entry->delete();
    }
}
