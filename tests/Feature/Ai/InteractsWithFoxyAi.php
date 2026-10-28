<?php

namespace Tests\Feature\Ai;

use App\Ai\Providers\FakeProvider;
use App\Ai\Providers\ModelProvider;
use App\Platform\ApiClients\ApiClient;
use App\Platform\Organizations\Organization;

trait InteractsWithFoxyAi
{
    protected FakeProvider $ai;

    protected function setUpFakeAi(): void
    {
        $this->ai = new FakeProvider;
        $this->app->instance(ModelProvider::class, $this->ai);
    }

    /**
     * @return array{0: Organization, 1: string}
     */
    protected function organizationWithKey(string $name = 'Clínica Demo', array $scopes = ['*'], array $attributes = []): array
    {
        $organization = Organization::create(['name' => $name] + $attributes);
        [, $key] = ApiClient::issue($organization, 'Teste', $scopes);

        return [$organization, $key];
    }

    protected function createProfile(string $key, string $slug = 'agenda-demo', string $knowledge = "## Remarcar atendimento\nAgenda › clique no atendimento › Remarcar."): void
    {
        $this->withToken($key)->putJson("/api/v1/host-profiles/{$slug}", ['name' => 'Agenda Demo', 'tone' => 'Simpático.'])->assertSuccessful();
        $this->withToken($key)->putJson("/api/v1/host-profiles/{$slug}/knowledge", ['content' => $knowledge])->assertOk();
    }

    protected function openConversation(string $key, string $userRef = 'u-1', string $slug = 'agenda-demo'): string
    {
        return $this->withToken($key)
            ->postJson('/api/v1/conversations', ['host_profile' => $slug, 'user_ref' => $userRef])
            ->assertCreated()
            ->json('conversation_id');
    }
}
