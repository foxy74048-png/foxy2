<?php

namespace App\Platform\ApiClients;

use App\Platform\Integrations\Integration;
use App\Platform\Organizations\Organization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Credencial de um sistema (integração). Pode ser:
 * - do sistema (organization_id nulo): atende todos os clientes; cada chamada informa o cliente em X-Foxy-Organization;
 * - presa a um cliente (organization_id preenchido): sempre age nesse cliente.
 * Não usa escopo global: é a partir dela que integração e organização da requisição são descobertas.
 */
class ApiClient extends Model
{
    use HasUlids;

    public const KEY_PREFIX = 'fxy_';

    protected $fillable = ['integration_id', 'organization_id', 'name', 'scopes'];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Cria a credencial e devolve a chave em texto — a única vez em que ela aparece.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(Integration $integration, string $name, array $scopes, ?Organization $organization = null): array
    {
        if ($organization && $organization->integration_id !== $integration->getKey()) {
            throw new \InvalidArgumentException('A organização não pertence a esta integração.');
        }

        $key = self::KEY_PREFIX.Str::random(40);

        $client = new self([
            'integration_id' => $integration->getKey(),
            'organization_id' => $organization?->getKey(),
            'name' => $name,
            'scopes' => array_values($scopes),
        ]);
        $client->key_prefix = substr($key, 0, 12);
        $client->key_hash = self::hash($key);
        $client->save();

        return [$client, $key];
    }

    public static function findActiveByKey(string $key): ?self
    {
        return self::query()
            ->where('key_hash', self::hash($key))
            ->whereNull('revoked_at')
            ->first();
    }

    public static function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function hasScope(string $scope): bool
    {
        $scopes = $this->scopes ?? [];

        return in_array('*', $scopes, true) || in_array($scope, $scopes, true);
    }

    public function revoke(): void
    {
        $this->forceFill(['revoked_at' => now()])->save();
    }
}
