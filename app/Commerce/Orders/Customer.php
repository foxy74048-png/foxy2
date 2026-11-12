<?php

namespace App\Commerce\Orders;

use App\Commerce\Finance\Receivable;
use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Comprador da loja (não é User do Core). Só os dados necessários para entregar e emitir nota (LGPD).
 */
class Customer extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $table = 'commerce_customers';

    protected $fillable = ['name', 'email', 'phone', 'document', 'city', 'state', 'notes'];

    /** Link da maleta: cria o código na primeira vez (ou um novo, quando $renew, e o antigo para de funcionar). */
    public function portalToken(bool $renew = false): string
    {
        if ($renew || ! $this->portal_token) {
            $this->forceFill(['portal_token' => Str::random(32)])->save();
        }

        return $this->portal_token;
    }

    public function receivables(): HasMany
    {
        return $this->hasMany(Receivable::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
