<?php

namespace Tests\Feature\Platform;

use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditEntry;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Organizations\Organization;
use App\Platform\Organizations\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class TenancyAndAuditTest extends TestCase
{
    use RefreshDatabase;

    private function runAs(Organization $organization, callable $callback): mixed
    {
        return app(ContextStore::class)->run(ExecutionContext::forApiClient($organization, 'client-x'), $callback);
    }

    public function test_data_of_one_organization_is_invisible_to_another(): void
    {
        $orgA = Organization::create(['name' => 'A']);
        $orgB = Organization::create(['name' => 'B']);

        $this->runAs($orgA, fn () => Audit::record('test.a'));
        $this->runAs($orgB, fn () => Audit::record('test.b'));

        $this->assertSame(['test.a'], $this->runAs($orgA, fn () => AuditEntry::pluck('action')->all()));
        $this->assertSame(['test.b'], $this->runAs($orgB, fn () => AuditEntry::pluck('action')->all()));
    }

    public function test_without_context_nothing_is_returned_unless_scope_is_removed_explicitly(): void
    {
        $org = Organization::create(['name' => 'A']);
        $this->runAs($org, fn () => Audit::record('test.a'));

        $this->assertSame(0, AuditEntry::count());
        $this->assertSame(1, AuditEntry::withoutGlobalScope(OrganizationScope::class)->count());
    }

    public function test_creating_business_data_without_context_fails(): void
    {
        $this->expectException(LogicException::class);

        AuditEntry::create(['principal_type' => 'system', 'action' => 'x', 'result' => 'ok', 'correlation_id' => 'c']);
    }

    public function test_audit_records_who_on_behalf_of_whom_and_correlation_id(): void
    {
        $org = Organization::create(['name' => 'A']);
        $context = ExecutionContext::forApiClient($org, 'client-1')->asAiAgentFor('user-42');

        $entry = app(ContextStore::class)->run($context, fn () => Audit::record('ai.tool_requested', 'ok', null, ['tool' => 'financeiro.saldos']));

        $this->assertSame('ai_agent', $entry->principal_type);
        $this->assertSame('client-1', $entry->principal_id);
        $this->assertSame('user-42', $entry->on_behalf_of);
        $this->assertSame($context->correlationId, $entry->correlation_id);
        $this->assertSame(['tool' => 'financeiro.saldos'], $entry->metadata);
    }

    public function test_audit_entries_cannot_be_changed_or_deleted(): void
    {
        $org = Organization::create(['name' => 'A']);
        $entry = $this->runAs($org, fn () => Audit::record('test'));

        try {
            $entry->update(['result' => 'erro']);
            $this->fail('Alteração deveria falhar.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $entry->delete();
    }
}
