<?php

namespace Tests\Feature\Platform;

use App\Platform\ApiClients\ApiClient;
use App\Platform\Integrations\Integration;
use App\Platform\Organizations\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_integration(): void
    {
        $this->artisan('foxy:integration:create', ['name' => 'Foxy Agenda'])->assertSuccessful();

        $this->assertDatabaseHas('integrations', ['name' => 'Foxy Agenda', 'status' => 'active']);
    }

    public function test_creates_system_key_with_scopes(): void
    {
        $integration = Integration::create(['name' => 'Foxy Agenda']);

        $this->artisan('foxy:api-client:create', [
            'integration' => $integration->id,
            'name' => 'Produção',
            '--scope' => ['organizations', 'ai.conversations'],
        ])->expectsOutputToContain('Guarde a chave agora')->assertSuccessful();

        $client = ApiClient::firstOrFail();
        $this->assertSame(['organizations', 'ai.conversations'], $client->scopes);
        $this->assertNull($client->organization_id);
        $this->assertStringStartsWith('fxy_', $client->key_prefix);
    }

    public function test_creates_key_pinned_to_a_client(): void
    {
        $integration = Integration::create(['name' => 'Foxy Agenda']);
        $clinic = Organization::create(['name' => 'Clínica', 'integration_id' => $integration->id]);

        $this->artisan('foxy:api-client:create', ['integration' => $integration->id, 'name' => 'Clínica', '--organization' => $clinic->id])
            ->assertSuccessful();

        $this->assertSame($clinic->id, ApiClient::firstOrFail()->organization_id);
    }

    public function test_rejects_unknown_integration_or_client_of_another_system(): void
    {
        $this->artisan('foxy:api-client:create', ['integration' => 'nao-existe', 'name' => 'X'])->assertFailed();

        $integration = Integration::create(['name' => 'A']);
        $foreign = Organization::create(['name' => 'X', 'integration_id' => Integration::create(['name' => 'B'])->id]);
        $this->artisan('foxy:api-client:create', ['integration' => $integration->id, 'name' => 'X', '--organization' => $foreign->id])->assertFailed();
    }

    public function test_revokes_api_client(): void
    {
        [$client] = ApiClient::issue(Integration::create(['name' => 'A']), 'Console', ['*']);

        $this->artisan('foxy:api-client:revoke', ['id' => $client->id])->assertSuccessful();

        $this->assertNotNull($client->fresh()->revoked_at);
    }
}
