<?php

namespace App\Finance\WhatsApp;

use App\Models\User;
use App\Platform\Audit\Audit;
use App\Platform\Messaging\WhatsAppConnections;
use App\Platform\Messaging\WhatsAppSender;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Ligar o WhatsApp à conta: a pessoa informa o número, o número da Foxy manda um código de 6 dígitos (vale 10
 * minutos, 5 tentativas) e ela digita na tela. Confirmado em outra conta, o número sai da conta antiga.
 */
class PhoneLinks
{
    public function __construct(private WhatsAppConnections $whatsapp) {}

    public static function instance(): string
    {
        return (string) config('finance.whatsapp.instance');
    }

    public function isAvailable(): bool
    {
        return $this->whatsapp->isConfigured() && filled(config('foxy.messaging.whatsapp.webhook_token'));
    }

    public function of(User $user): ?PhoneLink
    {
        return PhoneLink::where('user_id', $user->getKey())->first();
    }

    public function start(User $user, string $phone): PhoneLink
    {
        $number = WhatsAppSender::normalize($phone);
        if (strlen($number) < 12 || strlen($number) > 13) {
            throw ValidationException::withMessages(['phone' => 'Informe o número com DDD, por exemplo (11) 98765-4321.']);
        }

        $code = (string) random_int(100000, 999999);
        $link = PhoneLink::updateOrCreate(['user_id' => $user->getKey()], [
            'phone' => $number, 'code_hash' => Hash::make($code), 'code_expires_at' => now()->addMinutes(10), 'code_attempts' => 0, 'verified_at' => null,
        ]);

        $error = $this->whatsapp->send(self::instance(), $number, "Seu código do Foxy Finanças: *{$code}*\nDigite na tela para ligar este WhatsApp à sua conta. Vale por 10 minutos.\nNão pediu? É só ignorar.");
        if ($error) {
            throw ValidationException::withMessages(['phone' => 'Não deu para mandar o código agora ('.$error.'). Tente de novo em instantes.']);
        }

        return $link;
    }

    public function verify(User $user, string $code): PhoneLink
    {
        $link = $this->of($user);
        if (! $link || ! $link->isPending()) {
            throw ValidationException::withMessages(['code' => 'O código venceu. Peça um novo.']);
        }
        if ($link->code_attempts >= 5) {
            throw ValidationException::withMessages(['code' => 'Muitas tentativas. Peça um novo código.']);
        }
        if (! Hash::check(preg_replace('/\D/', '', $code), $link->code_hash)) {
            $link->increment('code_attempts');

            throw ValidationException::withMessages(['code' => 'Código errado. Confira a mensagem no WhatsApp.']);
        }

        // O mesmo número numa conta antiga deixa de valer: quem tem o aparelho é quem confirmou agora.
        PhoneLink::where('phone', $link->phone)->whereKeyNot($link->getKey())->delete();
        $link->forceFill(['verified_at' => now(), 'code_hash' => null, 'code_expires_at' => null, 'code_attempts' => 0])->save();
        Audit::record('finance.whatsapp.linked', subject: $user);

        return $link;
    }

    public function unlink(User $user): void
    {
        if ($link = $this->of($user)) {
            $link->delete();
            Audit::record('finance.whatsapp.unlinked', subject: $user);
        }
    }
}
