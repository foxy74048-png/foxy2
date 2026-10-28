<?php

namespace App\Platform\Context;

use App\Platform\Organizations\Organization;
use Illuminate\Support\Str;

/**
 * Quem está agindo, em nome de qual organização e de quem — ver docs/01-core-conceitual.md, seção 4.
 */
final readonly class ExecutionContext
{
    public const PRINCIPAL_API_CLIENT = 'api_client';

    public const PRINCIPAL_AI_AGENT = 'ai_agent';

    public const PRINCIPAL_USER = 'user';

    public const PRINCIPAL_SYSTEM = 'system';

    public function __construct(
        public Organization $organization,
        public string $principalType,
        public ?string $principalId,
        public string $source,
        public string $correlationId,
        public ?string $onBehalfOf = null,
    ) {}

    public static function forApiClient(Organization $organization, string $apiClientId): self
    {
        return new self($organization, self::PRINCIPAL_API_CLIENT, $apiClientId, 'api', (string) Str::ulid());
    }

    public static function forSystem(Organization $organization, string $source = 'job'): self
    {
        return new self($organization, self::PRINCIPAL_SYSTEM, null, $source, (string) Str::ulid());
    }

    /**
     * A IA agindo em nome de um usuário do anfitrião, com o mesmo correlation_id da requisição.
     */
    public function asAiAgentFor(string $userRef): self
    {
        return new self($this->organization, self::PRINCIPAL_AI_AGENT, $this->principalId, $this->source, $this->correlationId, $userRef);
    }

    public static function current(): self
    {
        return app(ContextStore::class)->get();
    }
}
