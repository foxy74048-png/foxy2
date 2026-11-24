<?php

namespace App\Finance\Console;

use App\Finance\Http\Middleware\ResolveWallet;
use App\Finance\Rewards\Challenge;
use App\Finance\Rewards\Rewards;
use App\Finance\Transactions\TransactionFilters;
use App\Finance\WhatsApp\PhoneLink;
use App\Finance\WhatsApp\PhoneLinks;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Messaging\WhatsAppConnections;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Domingo: para quem ligou o lembrete em "Meu perfil", uma mensagem com os desafios ativos do mês em cada carteira
 * dela e o nível da carteira. Quem não tem desafio ativo não recebe nada.
 */
class RemindChallenges extends Command
{
    protected $signature = 'financas:desafios-whatsapp';

    protected $description = 'Manda pelo WhatsApp como estão os desafios do mês (só para quem ligou o lembrete)';

    public function handle(ContextStore $contexts, WhatsAppConnections $whatsapp, Rewards $rewards): int
    {
        if (! app(PhoneLinks::class)->isAvailable()) {
            $this->warn('O WhatsApp do Foxy Finanças não está ligado.');

            return self::SUCCESS;
        }
        $month = today()->startOfMonth();
        $sent = 0;

        PhoneLink::verified()->where('challenge_reminders', true)->with('user')->each(function (PhoneLink $link) use ($contexts, $whatsapp, $rewards, $month, &$sent) {
            if (! $link->user) {
                return;
            }
            $blocks = [];
            foreach (ResolveWallet::walletsOf(ResolveWallet::membershipsOf($link->user->getKey()))->filter(fn ($o) => $o['wallet'] !== null) as $option) {
                $organization = $option['membership']->organization;
                $lines = $contexts->run(ExecutionContext::forUser($organization, $link->user->getKey(), 'whatsapp'), function () use ($rewards, $month) {
                    $active = Challenge::query()->where('month', $month->format('Y-m'))->where('status', Challenge::ACTIVE)->get();
                    if ($active->isEmpty()) {
                        return null;
                    }
                    $level = $rewards->level();

                    return [
                        'challenges' => $active->map(fn (Challenge $c) => '• '.$c->label.': '.$rewards->progress($c)['text'])->all(),
                        'level' => $level['name'].' · '.number_format($level['points'], 0, ',', '.').' pts',
                    ];
                });
                if ($lines) {
                    $blocks[] = '*'.$option['wallet']->name."* ({$lines['level']})\n".implode("\n", $lines['challenges']);
                }
            }
            if ($blocks === []) {
                return;
            }
            $text = '🦊 Seus desafios de '.TransactionFilters::monthName($month).":\n\n".implode("\n\n", $blocks)
                ."\n\nFaltam ".(today()->daysInMonth - today()->day)." dia(s) para o mês fechar. Detalhes em https://".config('foxy.hosts.financas').'/app/conquistas';
            try {
                if (! $whatsapp->send(PhoneLinks::instance(), $link->phone, Str::limit($text, 3500, '…'))) {
                    $sent++;
                }
            } catch (Throwable $e) {
                report($e);
            }
        });
        $this->info("{$sent} lembrete(s) enviado(s).");

        return self::SUCCESS;
    }
}
