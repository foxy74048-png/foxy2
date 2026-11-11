<?php

namespace App\Commerce\Supply;

use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Remessa de fornecedor (comprovante importado). Rascunho → aberta (estoque lançado) → acertada. */
class SupplyBatch extends Model
{
    use BelongsToOrganization, HasUlids;

    public const STATUSES = ['rascunho' => 'Conferindo', 'aberta' => 'Em aberto', 'acertada' => 'Acertada', 'cancelada' => 'Cancelada'];

    public const MODES = ['consignado' => 'Consignado (no fechamento paga o vendido e devolve o resto)', 'compra' => 'Comprado (paga tudo no fechamento)'];

    protected $table = 'commerce_supply_batches';

    protected $attributes = ['status' => 'rascunho'];

    protected $fillable = ['supplier', 'document_number', 'received_on', 'closing_on', 'status', 'price_basis', 'percent', 'total_cents', 'groups', 'notes', 'settled_at', 'created_by', 'supplier_id', 'source', 'installments'];

    protected function casts(): array
    {
        return ['received_on' => 'date', 'closing_on' => 'date', 'groups' => 'array', 'installments' => 'array', 'settled_at' => 'datetime', 'percent' => 'float', 'total_cents' => 'integer'];
    }

    public function supplierRecord(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplyItem::class, 'batch_id');
    }

    public function label(): string
    {
        return $this->supplier.($this->document_number ? ' nº '.$this->document_number : '');
    }

    public function hasConsigned(): bool
    {
        return $this->items->contains('mode', 'consignado');
    }
}
