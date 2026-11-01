<?php

namespace App\Platform\Organizations;

use App\Platform\Integrations\Integration;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um cliente de um sistema (ex.: uma clínica na Foxy Agenda). Sem escopo global: é buscada sempre
 * dentro da integração da credencial (ver AuthenticateApiClient e OrganizationController).
 */
class Organization extends Model
{
    use HasUlids;

    protected $fillable = ['integration_id', 'name', 'status', 'limits', 'external_ref'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['limits' => 'array'];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Limite configurado para a organização; sem valor próprio, vale o padrão de config/foxy.php.
     */
    public function limit(string $key): ?int
    {
        $value = $this->limits[$key] ?? config('foxy.limits')[$key] ?? null;

        return $value === null ? null : (int) $value;
    }
}
