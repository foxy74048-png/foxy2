<?php

namespace App\Commerce\Channels;

use Illuminate\Support\Facades\URL;

/**
 * Registro dos marketplaces (config/commerce.php, "channels"). Canal sem conector ainda = "em breve".
 * Nos testes: Channels::fake($connector) faz todos os canais usarem o conector falso.
 */
class Channels
{
    private static ?ChannelConnector $fake = null;

    public static function fake(?ChannelConnector $connector): void
    {
        self::$fake = $connector;
    }

    /** @return array<string, array{label: string, connector: ?string, title_max: int}> */
    public static function all(): array
    {
        return config('commerce.channels');
    }

    public static function available(string $channel): bool
    {
        return isset(self::all()[$channel]) && (self::$fake || filled(self::all()[$channel]['connector'] ?? null));
    }

    public static function connector(string $channel): ChannelConnector
    {
        if (self::$fake) {
            return self::$fake;
        }

        $class = self::all()[$channel]['connector'] ?? null;
        if (! $class) {
            throw new ChannelError('Este canal ainda não está disponível.');
        }

        return app($class);
    }

    /** Endereço de retorno da autorização (o mesmo cadastrado no app do marketplace). */
    public static function redirectUri(string $channel): string
    {
        return URL::route('commerce.channels.callback', ['channel' => $channel]);
    }
}
