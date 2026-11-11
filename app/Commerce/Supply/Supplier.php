<?php

namespace App\Commerce\Supply;

use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Fornecedora conhecida: reconhecida pelo CNPJ ou pelo nome do documento, com o jeito de lançar da última remessa. */
class Supplier extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $table = 'commerce_suppliers';

    protected $fillable = ['name', 'document_name', 'document', 'mode', 'price_basis', 'percent'];

    protected function casts(): array
    {
        return ['percent' => 'float'];
    }

    public function codes(): HasMany
    {
        return $this->hasMany(SupplierCode::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(SupplyBatch::class);
    }

    /** CNPJ formatado (ou CPF), para mostrar. */
    public function documentLabel(): ?string
    {
        $d = (string) $this->document;

        return match (strlen($d)) {
            14 => preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $d),
            11 => preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $d),
            default => $d ?: null,
        };
    }
}
