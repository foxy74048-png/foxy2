<?php

namespace App\Commerce\Console;

use App\Commerce\Channels\ChannelConnection;
use App\Commerce\Channels\ChannelSync;
use App\Commerce\Channels\InboxItem;
use Illuminate\Console\Command;

/**
 * Conferência dos marketplaces (a cada 10 min): renova tokens, busca pedidos que algum aviso perdeu e tenta
 * de novo os avisos que deram erro (até 5 vezes).
 */
class SyncChannels extends Command
{
    protected $signature = 'commerce:canais';

    protected $description = 'Confere os marketplaces conectados: tokens, pedidos recentes e avisos pendentes';

    public function handle(ChannelSync $sync): int
    {
        ChannelConnection::withoutGlobalScopes()->whereIn('status', ['active', 'needs_attention'])->get()
            ->each(fn (ChannelConnection $c) => $sync->as($c->organization_id, 'conferencia', fn () => $sync->reconcile(ChannelConnection::findOrFail($c->id))));

        InboxItem::whereNull('processed_at')->where('attempts', '<', 5)->where('received_at', '<', now()->subMinutes(2))
            ->orderBy('received_at')->limit(500)->get()
            ->each(fn (InboxItem $item) => $sync->processInbox($item));

        return self::SUCCESS;
    }
}
