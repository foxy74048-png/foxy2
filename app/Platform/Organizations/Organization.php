<?php

namespace App\Platform\Organizations;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    use HasUlids;

    protected $fillable = ['name', 'status', 'limits'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['limits' => 'array'];
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
