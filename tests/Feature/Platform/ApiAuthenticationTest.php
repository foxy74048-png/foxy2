<?php

namespace Tests\Feature\Platform;

use App\Platform\ApiClients\ApiClient;
use App\Platform\Organizations\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_valid_key_identifies_the_organization_and_returns_request_id(): void
    {
        $organization = Organization::create(['name' => 'Clínica Demo']);
        [$client, $key] = ApiClient::issue($organization, 'Console', ['*']);

        $response = $this->withToken($key)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('organization.id', $organization->id)
            ->assertJsonPath('organization.name', 'Clínica Demo')
            ->assertJsonPath('api_client.id', $client->id);

        $this->assertSame($response->json('request_id'), $response->headers->get('X-Request-Id'));
        $this->assertNotNull($client->fresh()->last_used_at);
    }

    public function test_key_is_stored_only_as_hash(): void
    {
        $organization = Organization::create(['name' => 'Org']);
        [$client, $key] = ApiClient::issue($organization, 'Console', ['*']);

        $this->assertDatabaseMissing('api_clients', ['key_hash' => $key]);
        $this->assertSame(hash('sha256', $key), $client->key_hash);
        $this->assertArrayNotHasKey('key_hash', $client->toArray());
    }

    public function test_revoked_key_is_rejected(): void
    {
        $organization = Organization::create(['name' => 'Org']);
        [$client, $key] = ApiClient::issue($organization, 'Console', ['*']);
        $client->revoke();

        $this->withToken($key)->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_key_of_suspended_organization_is_rejected(): void
    {
        $organization = Organization::create(['name' => 'Org', 'status' => 'suspended']);
        [, $key] = ApiClient::issue($organization, 'Console', ['*']);

        $this->withToken($key)->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_scope_middleware_blocks_key_without_the_scope(): void
    {
        Route::middleware(['api.client', 'api.scope:ai.conversations'])->get('/api/v1/_scope-test', fn () => ['ok' => true]);

        $organization = Organization::create(['name' => 'Org']);
        [, $limited] = ApiClient::issue($organization, 'Limitada', ['ai.host_profiles']);
        [, $allowed] = ApiClient::issue($organization, 'Liberada', ['ai.conversations']);

        $this->withToken($limited)->getJson('/api/v1/_scope-test')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');

        $this->withToken($allowed)->getJson('/api/v1/_scope-test')->assertOk();
    }

    public function test_rate_limit_per_key(): void
    {
        config(['foxy.api_rate_limit_per_minute' => 2]);
        $organization = Organization::create(['name' => 'Org']);
        [, $key] = ApiClient::issue($organization, 'Console', ['*']);

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
