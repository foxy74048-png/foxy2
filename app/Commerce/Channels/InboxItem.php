<?php

namespace App\Commerce\Channels;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Aviso de um canal (ex.: "pedido novo /orders/123"). Sem organização: chega sem usuário e a loja é achada pela
 * conta do vendedor. O conteúdo do aviso nunca é confiado: o pedido é buscado na API do canal.
 */
class InboxItem extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'commerce_channel_inbox';

    protected $fillable = ['channel', 'external_account_id', 'topic', 'resource', 'received_at', 'processed_at', 'attempts', 'last_error'];

    protected function casts(): array
    {
        return ['received_at' => 'datetime', 'processed_at' => 'datetime'];
    }
}
