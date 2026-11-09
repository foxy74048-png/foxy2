<?php

namespace App\Commerce\Orders;

use App\Commerce\Finance\Receivable;
use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Comprador da loja (não é User do Core). Só os dados necessários para entregar e emitir nota (LGPD).
 */
class Customer extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $table = 'commerce_customers';

    protected $fillable = ['name', 'email', 'phone', 'document', 'city', 'state', 'notes'];

    public function receivables(): HasMany
    {
        return $this->hasMany(Receivable::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
