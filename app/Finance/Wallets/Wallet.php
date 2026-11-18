<?php

namespace App\Finance\Wallets;

use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A carteira (1:1 com a organização): onde ficam as contas e os lançamentos. Uma pessoa pode estar em várias
 * (a pessoal e a do negócio, por exemplo) e troca no menu (docs/12, seção 1).
 */
class Wallet extends Model
{
    use BelongsToOrganization, HasUlids;

    public const PERSONAL = 'pessoal';

    public const BUSINESS = 'negocio';

    protected $table = 'finance_wallets';

    protected $fillable = ['organization_id', 'kind', 'name', 'is_mei', 'das_day'];

    protected $attributes = ['kind' => self::PERSONAL, 'is_mei' => false];

    protected function casts(): array
    {
        return ['is_mei' => 'boolean', 'das_day' => 'integer'];
    }

    public function isBusiness(): bool
    {
        return $this->kind === self::BUSINESS;
    }

    public function kindLabel(): string
    {
        return config("finance.wallet_kinds.{$this->kind}", $this->kind);
    }
}
