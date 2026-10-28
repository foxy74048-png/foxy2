<?php

namespace App\Platform\ApiClients;

use App\Platform\Organizations\Organization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Credencial de um sistema que consome a API. Não usa o escopo de organização:
 * é a partir dela que a organização da requisição é descoberta.
 */
class ApiClient extends Model
{
    use HasUlids;

    public const KEY_PREFIX = 'fxy_';

    protected $fillable = ['organization_id', 'name', 'scopes'];

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
    public static function issue(Organization $organization, string $name, array $scopes): array
    {
        $key = self::KEY_PREFIX.Str::random(40);

        $client = new self(['organization_id' => $organization->getKey(), 'name' => $name, 'scopes' => array_values($scopes)]);
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
