<?php

namespace Tests\Feature\Ai;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HostProfileApiTest extends TestCase
{
    use InteractsWithFoxyAi, RefreshDatabase;

    public function test_creates_and_updates_profile(): void
    {
        [, $key] = $this->organizationWithKey();

        $this->withToken($key)->putJson('/api/v1/host-profiles/agenda-demo', ['name' => 'Agenda'])
            ->assertCreated()
            ->assertJsonPath('knowledge_version', 0);

        $this->withToken($key)->putJson('/api/v1/host-profiles/agenda-demo', ['name' => 'Agenda 2', 'tone' => 'Formal.'])
            ->assertOk()
            ->assertJsonPath('name', 'Agenda 2');

        $this->assertDatabaseCount('host_profiles', 1);
        $this->assertDatabaseHas('audit_entries', ['action' => 'ai.host_profile_created']);
        $this->assertDatabaseHas('audit_entries', ['action' => 'ai.host_profile_updated']);
    }

    public function test_invalid_slug_is_rejected(): void
    {
        [, $key] = $this->organizationWithKey();

        $this->withToken($key)->putJson('/api/v1/host-profiles/Agenda_Demo', ['name' => 'X'])->assertNotFound();
    }

    public function test_knowledge_is_versioned_and_identical_content_does_not_create_version(): void
    {
        [, $key] = $this->organizationWithKey();
        $this->withToken($key)->putJson('/api/v1/host-profiles/agenda-demo', ['name' => 'Agenda']);

        $this->withToken($key)->putJson('/api/v1/host-profiles/agenda-demo/knowledge', ['content' => 'v1'])
            ->assertJson(['version' => 1, 'changed' => true]);
        $this->withToken($key)->putJson('/api/v1/host-profiles/agenda-demo/knowledge', ['content' => 'v1'])
            ->assertJson(['version' => 1, 'changed' => false]);
        $this->withToken($key)->putJson('/api/v1/host-profiles/agenda-demo/knowledge', ['content' => 'v2'])
            ->assertJson(['version' => 2, 'changed' => true]);

        $this->assertDatabaseCount('knowledge_packs', 2);
    }

    public function test_profiles_are_isolated_between_organizations(): void
    {
        [, $keyA] = $this->organizationWithKey('A');
        [, $keyB] = $this->organizationWithKey('B');
        $this->withToken($keyA)->putJson('/api/v1/host-profiles/agenda-demo', ['name' => 'Agenda A']);

        $this->withToken($keyB)->getJson('/api/v1/host-profiles/agenda-demo')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        // Mesmo slug em outra organização é outro perfil.
        $this->withToken($keyB)->putJson('/api/v1/host-profiles/agenda-demo', ['name' => 'Agenda B'])->assertCreated();
        $this->withToken($keyA)->getJson('/api/v1/host-profiles/agenda-demo')->assertJsonPath('name', 'Agenda A');
    }

    public function test_requires_host_profiles_scope(): void
    {
        [, $key] = $this->organizationWithKey('A', ['ai.conversations']);

        $this->withToken($key)->putJson('/api/v1/host-profiles/agenda-demo', ['name' => 'X'])->assertForbidden();
    }
}
