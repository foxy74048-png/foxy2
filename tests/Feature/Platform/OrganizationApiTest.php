<?php

namespace Tests\Feature\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Ai\InteractsWithFoxyAi;
use Tests\TestCase;

/**
 * O sistema cria e mantém os seus clientes pela API.
 */
class OrganizationApiTest extends TestCase
{
    use InteractsWithFoxyAi, RefreshDatabase;

    public function test_system_creates_its_clients_without_duplicating_by_external_ref(): void
    {
        [$integration, $key] = $this->integrationWithKey();

        $id = $this->withToken($key)->postJson('/api/v1/organizations', ['name' => 'Clínica Bem Viver', 'external_ref' => 'workspace-42'])
            ->assertCreated()
            ->assertJsonPath('status', 'active')
            ->json('id');

        $this->withToken($key)->postJson('/api/v1/organizations', ['name' => 'Clínica Bem Viver Ltda', 'external_ref' => 'workspace-42'])
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('name', 'Clínica Bem Viver Ltda');

        $this->assertDatabaseCount('organizations', 1);
        $this->assertDatabaseHas('organizations', ['id' => $id, 'integration_id' => $integration->id]);
        $this->assertDatabaseHas('audit_entries', ['action' => 'platform.organization_created', 'integration_id' => $integration->id, 'organization_id' => null]);

        $this->withToken($key)->getJson('/api/v1/organizations?external_ref=workspace-42')->assertJsonPath('data.0.id', $id);
    }

    public function test_system_suspends_a_client(): void
    {
        [, $key] = $this->integrationWithKey();
        $id = $this->withToken($key)->postJson('/api/v1/organizations', ['name' => 'X'])->json('id');

        $this->withToken($key)->patchJson("/api/v1/organizations/{$id}", ['status' => 'suspended'])->assertOk()->assertJsonPath('status', 'suspended');

        $this->withToken($key)->withHeader('X-Foxy-Organization', $id)->getJson('/api/v1/usage')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'organization_suspended');
    }

    public function test_systems_never_see_each_others_clients(): void
    {
        [, $keyA] = $this->integrationWithKey('A');
        [, $keyB] = $this->integrationWithKey('B');
        $id = $this->withToken($keyA)->postJson('/api/v1/organizations', ['name' => 'Cliente A', 'external_ref' => 'mesmo'])->json('id');

        $this->withToken($keyB)->getJson("/api/v1/organizations/{$id}")->assertNotFound();
        $this->withToken($keyB)->patchJson("/api/v1/organizations/{$id}", ['status' => 'suspended'])->assertNotFound();
        $this->withToken($keyB)->getJson('/api/v1/organizations')->assertJsonCount(0, 'data');

        // O mesmo external_ref em outro sistema é outro cliente.
        $this->withToken($keyB)->postJson('/api/v1/organizations', ['name' => 'Cliente B', 'external_ref' => 'mesmo'])->assertCreated();
    }

    public function test_requires_organizations_scope(): void
    {
        [, $key] = $this->integrationWithKey('A', ['ai.conversations']);

        $this->withToken($key)->postJson('/api/v1/organizations', ['name' => 'X'])->assertForbidden();
    }

    public function test_manual_and_tools_are_shared_by_all_clients_of_the_system(): void
    {
        $this->setUpFakeAi();
        [, $key] = $this->integrationWithKey();
        $this->createProfile($key);
        $a = $this->withToken($key)->postJson('/api/v1/organizations', ['name' => 'A'])->json('id');
        $b = $this->withToken($key)->postJson('/api/v1/organizations', ['name' => 'B'])->json('id');

        foreach ([$a, $b] as $org) {
            $this->withToken($key)->withHeader('X-Foxy-Organization', $org)
                ->postJson('/api/v1/conversations', ['host_profile' => 'agenda-demo', 'user_ref' => 'u-1'])
                ->assertCreated();
        }

        $this->assertDatabaseCount('host_profiles', 1);
        $this->assertDatabaseHas('conversations', ['organization_id' => $a]);
        $this->assertDatabaseHas('conversations', ['organization_id' => $b]);
    }
}
