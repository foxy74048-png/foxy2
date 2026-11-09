<?php

namespace App\Commerce\Catalog;

use App\Platform\Files\File;
use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Foto do produto. Sem option_name vale para todas as variações; com option_name/option_value vale só para
 * as variações com aquele valor (ex.: Cor = Nude).
 */
class ProductImage extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $table = 'commerce_product_images';

    protected $fillable = ['product_id', 'file_id', 'position', 'option_name', 'option_value'];

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function isGeneral(): bool
    {
        return $this->option_name === null;
    }

    /** Vale para uma variação com estas opções (ex.: ['Tamanho' => 'M', 'Cor' => 'Nude'])? */
    public function appliesTo(?array $options): bool
    {
        return $this->isGeneral() || (($options ?? [])[$this->option_name] ?? null) === $this->option_value;
    }

    /** "Cor: Nude" (vazio quando vale para todas). */
    public function optionLabel(): string
    {
        return $this->isGeneral() ? '' : $this->option_name.': '.$this->option_value;
    }
}
