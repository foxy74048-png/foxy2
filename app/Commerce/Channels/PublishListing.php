<?php

namespace App\Commerce\Channels;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as QueueableJob;

/**
 * Publica o anúncio no canal (fora da requisição: o canal pode demorar).
 */
class PublishListing implements ShouldQueue
{
    use QueueableJob;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public string $organizationId, public string $listingId) {}

    public function handle(ChannelSync $sync): void
    {
        $sync->as($this->organizationId, 'canal', fn () => $sync->publish(Listing::findOrFail($this->listingId)));
    }
}
