<?php

namespace App\Commerce\Channels;

use Illuminate\Support\Carbon;

/**
 * Canal que entrega a nota fiscal emitida por ele (ou enviada pelo vendedor) para um pedido. Opcional: o conector
 * só implementa quando a API do canal for confirmada. A conferência periódica busca as notas que faltam.
 */
interface ProvidesInvoices
{
    /**
     * @return array{access_key: string, number: ?string, series: ?string, issued_at: ?Carbon, total_cents: ?int, xml: ?string}|null
     */
    public function fetchInvoice(ChannelConnection $connection, string $externalOrderId): ?array;
}
