<?php

namespace App\Platform\Announcements;

use App\Platform\Products\ProductAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um envio de um comunicado para uma conta, num canal (um por comunicado + conta + canal).
 */
class AnnouncementDelivery extends Model
{
    public const STATUSES = ['pendente' => 'Na fila', 'enviado' => 'Enviado', 'falhou' => 'Falhou', 'pulado' => 'Não enviado'];

    protected $fillable = ['announcement_id', 'product_account_id', 'channel', 'to', 'status', 'error', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ProductAccount::class, 'product_account_id');
    }
}
