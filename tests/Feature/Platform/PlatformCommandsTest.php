<?php

namespace Tests\Feature\Platform;

use App\Platform\ApiClients\ApiClient;
use App\Platform\Organizations\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_organization(): void
    {
        $this->artisan('foxy:organization:create', ['name' => 'Equipe Foxy'])->assertSuccessful();

        $this->assertDatabaseHas('organizations', ['name' => 'Equipe Foxy', 'status' => 'active']);
    }

    public function test_creates_api_client_with_scopes(): void
    {
        $org = Organization::create(['name' => 'Equipe Foxy']);

        $this->artisan('foxy:api-client:create', [
            'organization' => $org->id,
            'name' => 'Console',
            '--scope' => ['ai.conversations', 'ai.host_profiles'],
        ])->expectsOutputToContain('Guarde a chave agora')->assertSuccessful();

        $client = ApiClient::firstOrFail();
        $this->assertSame(['ai.conversations', 'ai.host_profiles'], $client->scopes);
        $this->assertStringStartsWith('fxy_', $client->key_prefix);
    }

    public function test_api_client_for_unknown_organization_fails(): void
    {
        $this->artisan('foxy:api-client:create', ['organization' => 'nao-existe', 'name' => 'X'])->assertFailed();
    }

    public function test_revokes_api_client(): void
    {
        [$client] = ApiClient::issue(Organization::create(['name' => 'Org']), 'Console', ['*']);

        $this->artisan('foxy:api-client:revoke', ['id' => $client->id])->assertSuccessful();

        $this->assertNotNull($client->fresh()->revoked_at);
    }
}
