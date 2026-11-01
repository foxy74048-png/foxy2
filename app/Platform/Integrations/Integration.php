<?php

namespace App\Platform\Integrations;

use App\Platform\Organizations\Organization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um sistema da Foxy (ex.: Foxy Agenda, Commerce) que usa o Core e cria organizações para os seus clientes.
 */
class Integration extends Model
{
    use HasUlids;

    protected $fillable = ['name', 'status'];

    protected $attributes = ['status' => 'active'];

    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
