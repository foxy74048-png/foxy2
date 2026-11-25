<?php

namespace App\Finance\Console;

use App\Finance\Http\Middleware\ResolveWallet;
use App\Finance\WhatsApp\PhoneLink;
use App\Finance\WhatsApp\PhoneLinks;
use App\Finance\WhatsApp\WeeklySummary;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Messaging\WhatsAppConnections;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Domingo de manhã: para quem ligou o resumo semanal em "Meu perfil", uma mensagem com a semana de cada carteira
 * dela (sem IA, sem crédito). Carteira sem nada na semana nem nada vencendo não entra; sem nada, não manda.
 */
class SendWeeklySummary extends Command
{
    protected $signature = 'financas:resumo-semanal';

    protected $description = 'Manda pelo WhatsApp o resumo da semana (só para quem ligou o resumo semanal)';

    public function handle(ContextStore $contexts, WhatsAppConnections $whatsapp, WeeklySummary $summary): int
    {
        if (! app(PhoneLinks::class)->isAvailable()) {
            $this->warn('O WhatsApp do Foxy Finanças não está ligado.');

            return self::SUCCESS;
        }
        $sent = 0;

        PhoneLink::verified()->where('weekly_summary', true)->with('user')->each(function (PhoneLink $link) use ($contexts, $whatsapp, $summary, &$sent) {
            if (! $link->user) {
                return;
            }
            $blocks = [];
            foreach (ResolveWallet::walletsOf(ResolveWallet::membershipsOf($link->user->getKey()))->filter(fn ($o) => $o['wallet'] !== null) as $option) {
                $text = $contexts->run(
                    ExecutionContext::forUser($option['membership']->organization, $link->user->getKey(), 'whatsapp'),
                    fn () => $summary->for($option['wallet']),
                );
                if ($text) {
                    $blocks[] = $text;
                }
            }
            if ($blocks === []) {
                return;
            }
            try {
                if (! $whatsapp->send(PhoneLinks::instance(), $link->phone, Str::limit(implode("\n\n—\n\n", $blocks), 3500, '…'))) {
                    $sent++;
                }
            } catch (Throwable $e) {
                report($e);
            }
        });
        $this->info("{$sent} resumo(s) enviado(s).");

        return self::SUCCESS;
    }
}
