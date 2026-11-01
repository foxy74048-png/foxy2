<?php

namespace Tests\Feature\Platform;

use App\Platform\ApiClients\ApiClient;
use App\Platform\Integrations\Integration;
use App\Platform\Organizations\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Ai\InteractsWithFoxyAi;
use Tests\TestCase;

class ApiAuthenticationTest extends TestCase
{
    use InteractsWithFoxyAi, RefreshDatabase;

    public function test_request_without_key_is_rejected_in_the_standard_error_format(): void
    {
        $this->getJson('/api/v1/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_invalid_key_is_rejected(): void
    {
        $this->withToken('fxy_chave-que-nao-existe')->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_key_pinned_to_a_client_identifies_integration_and_organization(): void
    {
        [$organization, $key] = $this->organizationWithKey('Clínica Demo');

        $response = $this->withToken($key)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('integration.id', $organization->integration_id)
            ->assertJsonPath('organization.id', $organization->id)
            ->assertJsonPath('organization.name', 'Clínica Demo')
            ->assertJsonPath('api_client.pinned_to_organization', true);

        $this->assertSame($response->json('request_id'), $response->headers->get('X-Request-Id'));
    }

    public function test_system_key_chooses_the_client_by_header(): void
    {
        [$integration, $key] = $this->integrationWithKey();
        $clinic = Organization::create(['name' => 'Clínica A', 'integration_id' => $integration->id]);

        $this->withToken($key)->getJson('/api/v1/me')->assertOk()->assertJsonPath('organization', null);

        $this->withToken($key)->withHeader('X-Foxy-Organization', $clinic->id)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('organization.name', 'Clínica A');
    }

    public function test_system_key_cannot_reach_a_client_of_another_system(): void
    {
        [, $key] = $this->integrationWithKey('Sistema A');
        $other = Integration::create(['name' => 'Sistema B']);
        $foreign = Organization::create(['name' => 'Cliente do B', 'integration_id' => $other->id]);

        $this->withToken($key)->withHeader('X-Foxy-Organization', $foreign->id)->getJson('/api/v1/me')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'organization_not_found');
    }

    public function test_pinned_key_cannot_switch_to_another_client(): void
    {
        [$organization, $key] = $this->organizationWithKey();
        $sibling = Organization::create(['name' => 'Outra', 'integration_id' => $organization->integration_id]);

        $this->withToken($key)->withHeader('X-Foxy-Organization', $sibling->id)->getJson('/api/v1/me')
            ->assertForbidden();
    }

    public function test_client_routes_require_a_client(): void
    {
        [, $key] = $this->integrationWithKey();

        $this->withToken($key)->postJson('/api/v1/conversations', ['host_profile' => 'x', 'user_ref' => 'u'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'organization_required');
    }

    public function test_key_is_stored_only_as_hash(): void
    {
        [$integration] = $this->integrationWithKey();
        [$client, $key] = ApiClient::issue($integration, 'Console', ['*']);

        $this->assertDatabaseMissing('api_clients', ['key_hash' => $key]);
        $this->assertSame(hash('sha256', $key), $client->key_hash);
        $this->assertArrayNotHasKey('key_hash', $client->toArray());
    }

    public function test_key_cannot_be_pinned_to_a_client_of_another_system(): void
    {
        [$integration] = $this->integrationWithKey('A');
        $foreign = Organization::create(['name' => 'X', 'integration_id' => Integration::create(['name' => 'B'])->id]);

        $this->expectException(\InvalidArgumentException::class);
        ApiClient::issue($integration, 'Errada', ['*'], $foreign);
    }

    public function test_revoked_key_is_rejected(): void
    {
        [$integration] = $this->integrationWithKey();
        [$client, $key] = ApiClient::issue($integration, 'Console', ['*']);
        $client->revoke();

        $this->withToken($key)->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_suspended_system_or_client_is_blocked(): void
    {
        [$organization, $key] = $this->organizationWithKey();

        $organization->update(['status' => 'suspended']);
        $this->withToken($key)->getJson('/api/v1/me')->assertForbidden()->assertJsonPath('error.code', 'organization_suspended');

        $organization->update(['status' => 'active']);
        $organization->integration->update(['status' => 'suspended']);
        $this->withToken($key)->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_scope_middleware_blocks_key_without_the_scope(): void
    {
        Route::middleware(['api.client', 'api.scope:ai.conversations'])->get('/api/v1/_scope-test', fn () => ['ok' => true]);

        [$integration] = $this->integrationWithKey();
        [, $limited] = ApiClient::issue($integration, 'Limitada', ['ai.host_profiles']);
        [, $allowed] = ApiClient::issue($integration, 'Liberada', ['ai.conversations']);

        $this->withToken($limited)->getJson('/api/v1/_scope-test')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');

        $this->withToken($allowed)->getJson('/api/v1/_scope-test')->assertOk();
    }

    public function test_rate_limit_per_key(): void
    {
        config(['foxy.api_rate_limit_per_minute' => 2]);
        [, $key] = $this->integrationWithKey();

        $this->withToken($key)->getJson('/api/v1/me')->assertOk();
        $this->withToken($key)->getJson('/api/v1/me')->assertOk();
        $this->withToken($key)->getJson('/api/v1/me')
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'rate_limited');
    }

    public function test_unknown_api_route_uses_standard_error_format(): void
    {
        $this->getJson('/api/v1/nao-existe')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');
    }
}
