<?php

namespace App\Commerce\Console;

use App\Commerce\Finance\Receivable;
use App\Commerce\Stores\Store;
use App\Commerce\WhatsApp\StoreWhatsApp;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use Illuminate\Console\Command;

/**
 * Lembretes automáticos das contas a receber pelo WhatsApp de cada loja (só das lojas que ligaram e estão
 * conectadas): antes do vencimento, no dia e nos dias de atraso escolhidos. Cada etapa sai uma vez só
 * (anotada na conta). Só em horário comercial, com pausa entre mensagens para o número não ser bloqueado.
 */
class SendReminders extends Command
{
    protected $signature = 'commerce:lembretes {--agora : ignora o horário comercial}';

    protected $description = 'Envia os lembretes de vencimento das contas a receber pelo WhatsApp das lojas';

    public function handle(ContextStore $contexts, StoreWhatsApp $whatsapp): int
    {
        $hour = (int) now()->timezone('America/Sao_Paulo')->format('H');
        if (! $this->option('agora') && ($hour < 9 || $hour >= 19)) {
            return self::SUCCESS;
        }

        $sent = 0;
        $stores = Store::withoutGlobalScopes()->whereNotNull('whatsapp_settings')->with('organization')->get()
            ->filter(fn (Store $s) => StoreWhatsApp::settings($s)['reminders'] && $s->organization?->status === 'active');

        foreach ($stores as $store) {
            $contexts->run(ExecutionContext::forSystem($store->organization, 'lembretes'), function () use ($store, $whatsapp, &$sent) {
                $settings = StoreWhatsApp::settings($store);
                $candidates = Receivable::with('customer')->open()
                    ->whereHas('customer', fn ($q) => $q->whereNotNull('phone')->where('phone', '!=', ''))
                    ->whereBetween('due_date', [today()->subDays(max([0, ...$settings['after_days']]))->toDateString(), today()->addDays($settings['days_before'])->toDateString()])
                    ->get()
                    ->map(fn (Receivable $r) => [$r, StoreWhatsApp::reminderStage($settings, $r)])
                    ->filter(fn ($pair) => $pair[1] !== null);

                if ($candidates->isEmpty() || ! $whatsapp->isConnected($store)) {
                    return;
                }

                foreach ($candidates as [$receivable, [$stage, $kind]]) {
                    $message = $whatsapp->send($store, $receivable, $kind);
                    // Anota a etapa mesmo se falhar (número inválido etc.): não insiste de hora em hora.
                    $receivable->update(['reminders' => array_merge($receivable->reminders ?? [], [$stage => now()->toIso8601String()])]);
                    $message->status === 'enviado' && $sent++;
                    $this->pause();
                }
            });
        }

        $this->info("Lembretes enviados: {$sent}");

        return self::SUCCESS;
    }

    private function pause(): void
    {
        [$min, $max] = config('commerce.whatsapp_pause_seconds', [6, 10]);
        if ($max > 0) {
            sleep(random_int($min, $max));
        }
    }
}
