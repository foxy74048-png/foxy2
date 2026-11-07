<?php

namespace App\Commerce\Stores;

use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A loja (1:1 com a organização). A loja própria pública acha a loja pelo slug, sem usuário logado:
 * nesse caso tirar o escopo explicitamente e montar o contexto da loja achada (docs/09, seção 7).
 */
class Store extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $table = 'commerce_stores';

    protected $fillable = ['organization_id', 'slug', 'name', 'whatsapp'];

    /**
     * Endereço livre a partir do nome: sem acento, sem palavra reservada, com -2, -3... se já existir.
     */
    public static function availableSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name), 50, '') ?: 'loja';

        if (in_array($base, config('commerce.reserved_slugs'), true)) {
            $base .= '-loja';
        }

        $slug = $base;
        for ($i = 2; static::withoutGlobalScopes()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
