<?php

namespace App\Commerce;

use App\Commerce\Accounts\AccountSync;
use App\Commerce\Channels\ListingVariant;
use App\Commerce\Channels\PushListingStock;
use App\Commerce\Inventory\StockChanged;
use App\Commerce\Inventory\StockService;
use App\Platform\Billing\SubscriptionChanged;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
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

        // Hoje só clientes do Commerce confirmam e-mail e trocam senha pelo Core: os links levam ao endereço
        // do Commerce. Quando outro produto usar o login único, o link passa a depender do produto da pessoa.
        VerifyEmail::createUrlUsing(fn ($user) => URL::temporarySignedRoute(
            'commerce.verification.verify',
            now()->addMinutes(60),
            ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
        ));
        VerifyEmail::toMailUsing(fn ($user, string $url) => (new MailMessage)
            ->subject('Confirme o seu e-mail no Foxy Commerce')
            ->greeting("Olá, {$user->name}!")
            ->line('Falta só confirmar o seu e-mail para começar a usar o Foxy Commerce.')
            ->action('Confirmar e-mail', $url)
            ->line('O link vale por 60 minutos. Se você não criou esta conta, é só ignorar.')
            ->salutation('Equipe Foxy'));

        ResetPassword::createUrlUsing(fn ($user, string $token) => route('commerce.password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()]));
        ResetPassword::toMailUsing(fn ($user, string $token) => (new MailMessage)
            ->subject('Criar uma nova senha no Foxy Commerce')
            ->greeting("Olá, {$user->name}!")
            ->line('Recebemos um pedido para criar uma nova senha para a sua conta.')
            ->action('Criar nova senha', route('commerce.password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()]))
            ->line('O link vale por 60 minutos. Se não foi você, é só ignorar: a senha atual continua valendo.')
            ->salutation('Equipe Foxy'));
    }
}
