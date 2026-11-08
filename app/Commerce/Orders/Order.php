<?php

namespace App\Commerce\Orders;

use App\Commerce\Fiscal\FiscalDocument;
use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pedido de qualquer canal, numa fila só. Só OrderService muda o status (e o estoque junto).
 */
class Order extends Model
{
    use BelongsToOrganization, HasUlids;

    public const STATUSES = [
        'awaiting_payment' => 'Aguardando pagamento',
        'paid' => 'Pago',
        'in_fulfillment' => 'Separando',
        'shipped' => 'Enviado',
        'completed' => 'Concluído',
        'canceled' => 'Cancelado',
    ];

    public const CHANNELS = [
        'balcao' => 'Balcão',
        'whatsapp' => 'WhatsApp',
        'instagram' => 'Instagram',
        'loja' => 'Loja própria',
        'mercadolivre' => 'Mercado Livre',
        'shopee' => 'Shopee',
        'magalu' => 'Magalu',
        'shein' => 'Shein',
    ];

    /** Canais que entram por digitação no sistema (os outros chegam pelos conectores). */
    public const MANUAL_CHANNELS = ['balcao', 'whatsapp', 'instagram'];

    public const PAYMENT_METHODS = [
        'pix' => 'Pix',
        'cartao_credito' => 'Cartão de crédito',
        'cartao_debito' => 'Cartão de débito',
        'dinheiro' => 'Dinheiro',
        'boleto' => 'Boleto',
        'marketplace' => 'Pelo marketplace',
    ];

    /** Pedidos que contam como venda no financeiro. */
    public const SOLD = ['paid', 'in_fulfillment', 'shipped', 'completed'];

    protected $table = 'commerce_orders';

    protected $fillable = [
        'number', 'channel', 'external_id', 'customer_id', 'status', 'payment_method', 'subtotal_cents', 'discount_cents',
        'shipping_cents', 'fee_cents', 'total_cents', 'cost_cents', 'tracking_code', 'notes', 'created_by', 'placed_at',
        'paid_at', 'shipped_at', 'completed_at', 'canceled_at', 'reserved_until',
    ];

    protected function casts(): array
    {
        return [
            'placed_at' => 'datetime', 'paid_at' => 'datetime', 'shipped_at' => 'datetime', 'completed_at' => 'datetime',
            'canceled_at' => 'datetime', 'reserved_until' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function fiscalDocuments(): HasMany
    {
        return $this->hasMany(FiscalDocument::class);
    }

    public function scopeSold(Builder $query): void
    {
        $query->whereIn('status', self::SOLD);
    }

    /** Lucro bruto: total − frete cobrado − taxas do canal − custo das mercadorias. */
    public function profitCents(): int
    {
        return $this->total_cents - $this->shipping_cents - $this->fee_cents - $this->cost_cents;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function channelLabel(): string
    {
        return self::CHANNELS[$this->channel] ?? $this->channel;
    }
}
