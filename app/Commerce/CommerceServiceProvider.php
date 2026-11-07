<?php

namespace App\Commerce;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class CommerceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
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
