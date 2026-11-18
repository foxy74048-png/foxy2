<?php

namespace App\Platform\Identity;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;

/**
 * Login único de clientes (mesma tabela users) com uma tela de entrar por produto. Os e-mails de confirmar o
 * e-mail e de criar nova senha levam ao produto em que a pessoa está agora (pelo endereço da requisição;
 * config foxy.logins). Fora de um endereço de produto (comando, fila), vale o primeiro da lista.
 */
class ProductLogin
{
    /** @return array{key: string, name: string, routes: string} */
    public static function current(): array
    {
        $logins = config('foxy.logins');
        $host = strtolower(request()->getHost());

        foreach ($logins as $key => $login) {
            if ($host === strtolower((string) config("foxy.hosts.{$key}"))) {
                return ['key' => $key] + $login;
            }
        }

        $key = array_key_first($logins);

        return ['key' => $key] + $logins[$key];
    }

    public static function register(): void
    {
        VerifyEmail::createUrlUsing(fn ($user) => URL::temporarySignedRoute(
            self::current()['routes'].'.verification.verify',
            now()->addMinutes(60),
            ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
        ));
        VerifyEmail::toMailUsing(function ($user, string $url) {
            $product = self::current()['name'];

            return (new MailMessage)
                ->subject("Confirme o seu e-mail no {$product}")
                ->greeting("Olá, {$user->name}!")
                ->line("Falta só confirmar o seu e-mail para começar a usar o {$product}.")
                ->action('Confirmar e-mail', $url)
                ->line('O link vale por 60 minutos. Se você não criou esta conta, é só ignorar.')
                ->salutation('Equipe Foxy');
        });

        $resetUrl = fn ($user, string $token) => route(self::current()['routes'].'.password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()]);
        ResetPassword::createUrlUsing($resetUrl);
        ResetPassword::toMailUsing(fn ($user, string $token) => (new MailMessage)
            ->subject('Criar uma nova senha no '.self::current()['name'])
            ->greeting("Olá, {$user->name}!")
            ->line('Recebemos um pedido para criar uma nova senha para a sua conta.')
            ->action('Criar nova senha', $resetUrl($user, $token))
            ->line('O link vale por 60 minutos. Se não foi você, é só ignorar: a senha atual continua valendo.')
            ->salutation('Equipe Foxy'));
    }
}
