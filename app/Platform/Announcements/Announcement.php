<?php

namespace App\Platform\Announcements;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Comunicado da Foxy para os clientes dos produtos (melhorias, novidades, avisos), por e-mail e/ou WhatsApp.
 * Público: produto, situações e planos (docs/08, fase 2). Quem saiu da lista daquele canal não recebe.
 */
class Announcement extends Model
{
    use HasUlids;

    public const CHANNELS = ['email' => 'E-mail', 'whatsapp' => 'WhatsApp', 'ambos' => 'E-mail e WhatsApp'];

    public const STATUSES = ['rascunho' => 'Rascunho', 'enviando' => 'Enviando', 'enviado' => 'Enviado', 'cancelado' => 'Cancelado'];

    protected $fillable = ['title', 'channel', 'subject', 'body', 'audience', 'status', 'created_by', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['audience' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(AnnouncementDelivery::class);
    }

    /** @return list<string> */
    public function channels(): array
    {
        return $this->channel === 'ambos' ? ['email', 'whatsapp'] : [$this->channel];
    }
}
