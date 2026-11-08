<?php

namespace App\Commerce\Channels;

use App\Commerce\Catalog\Product;
use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Anúncio criado no Foxy para um canal: título, preço e categoria próprios; o estoque vem das variantes ligadas.
 */
class Listing extends Model
{
    use BelongsToOrganization, HasUlids;

    public const STATUSES = [
        'draft' => 'Rascunho', 'publishing' => 'Publicando', 'active' => 'No ar', 'paused' => 'Pausado',
        'error' => 'Com erro', 'closed' => 'Encerrado',
    ];

    protected $table = 'commerce_listings';

    protected $fillable = ['connection_id', 'product_id', 'external_id', 'title', 'price_cents', 'external_category', 'attributes', 'status', 'last_error', 'synced_at'];

    protected function casts(): array
    {
        return ['attributes' => 'array', 'synced_at' => 'datetime', 'price_cents' => 'integer'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ChannelConnection::class, 'connection_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ListingVariant::class);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
