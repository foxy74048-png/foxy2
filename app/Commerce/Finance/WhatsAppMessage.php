<?php

namespace App\Commerce\Finance;

use App\Commerce\Orders\Customer;
use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Histórico das mensagens de WhatsApp da loja (cobranças e lembretes): o que saiu, para quem e quando. */
class WhatsAppMessage extends Model
{
    use BelongsToOrganization, HasUlids;

    public const KINDS = [
        'cobranca' => 'Cobrança', 'lembrete_antes' => 'Lembrete antes do vencimento', 'lembrete_dia' => 'Lembrete no dia',
        'lembrete_atraso' => 'Lembrete de atraso',
    ];

    public const STATUSES = ['enviado' => 'Enviado', 'falhou' => 'Não saiu', 'manual' => 'Aberto no WhatsApp'];

    protected $table = 'commerce_whatsapp_messages';

    protected $fillable = ['customer_id', 'receivable_id', 'kind', 'phone', 'text', 'status', 'error', 'created_by'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class);
    }
}
