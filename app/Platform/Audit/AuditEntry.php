<?php

namespace App\Platform\Audit;

use App\Platform\Integrations\BelongsToIntegration;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Registro só-inclusão: não pode ser alterado nem apagado. Visível pela integração (e filtrável por organização).
 */
class AuditEntry extends Model
{
    use BelongsToIntegration, HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Auditoria não pode ser alterada.'));
        static::deleting(fn () => throw new LogicException('Auditoria não pode ser apagada.'));
    }
}
