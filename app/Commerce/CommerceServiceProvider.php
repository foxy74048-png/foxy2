<?php

namespace App\Commerce;

use App\Commerce\Accounts\AccountSync;
use App\Commerce\Channels\ListingVariant;
use App\Commerce\Channels\PushListingStock;
use App\Commerce\Inventory\StockChanged;
use App\Commerce\Inventory\StockService;
use App\Platform\Billing\SubscriptionChanged;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class CommerceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Um por requisição/job: junta as mudanças de estoque e avisa uma vez só (StockChanged).
        $this->app->scoped(StockService::class);
    }

    public function boot(): void
    {
        // Loja própria: visual da loja do lojista (<x-storefront::layout>), separado do Core.
        Blade::anonymousComponentPath(resource_path('views/storefront'), 'storefront');

        // Estoque mudou: agenda o envio do novo disponível para cada anúncio no ar que usa essas variantes.
        Event::listen(function (StockChanged $event) {
            ListingVariant::withoutGlobalScopes()
                ->where('organization_id', $event->organizationId)
                ->whereIn('variant_id', $event->variantIds)
                ->whereHas('listing', fn ($q) => $q->withoutGlobalScopes()->where('status', 'active'))
                ->distinct()->pluck('listing_id')
                ->each(fn (string $listingId) => PushListingStock::dispatch($event->organizationId, $listingId));
        });

        // Assinatura mudou (Stripe, Foxy Admin): a conta da loja no Admin acompanha.
        Event::listen(function (SubscriptionChanged $event) {
            if ($event->subscription->product === Commerce::SLUG) {
                AccountSync::sync($event->subscription->organization);
            }
        });

        // Os e-mails de confirmar e-mail e de nova senha ficam em App\Platform\Identity\ProductLogin.
    }
}
