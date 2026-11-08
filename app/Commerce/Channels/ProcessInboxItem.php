<?php

namespace App\Commerce\Channels;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as QueueableJob;

/**
 * Processa um aviso recebido de um canal.
 */
class ProcessInboxItem implements ShouldQueue
{
    use QueueableJob;

    public function __construct(public string $inboxId) {}

    public function handle(ChannelSync $sync): void
    {
        $item = InboxItem::find($this->inboxId);
        if ($item && ! $item->processed_at) {
            $sync->processInbox($item);
        }
    }
}
