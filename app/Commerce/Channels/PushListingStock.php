<?php

namespace App\Commerce\Channels;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as QueueableJob;

/**
 * Envia o estoque de um anúncio. Único por anúncio: várias vendas seguidas viram um envio só.
 */
class PushListingStock implements ShouldBeUnique, ShouldQueue
{
    use QueueableJob;

    public int $tries = 5;

    public array $backoff = [10, 30, 60, 300];

    public int $uniqueFor = 60;

    public function __construct(public string $organizationId, public string $listingId) {}

    public function uniqueId(): string
    {
        return $this->listingId;
    }

    public function handle(ChannelSync $sync): void
    {
        $sync->as($this->organizationId, 'canal', function () use ($sync) {
            $listing = Listing::find($this->listingId);
            $listing && $sync->pushStock($listing);
        });
    }
}
