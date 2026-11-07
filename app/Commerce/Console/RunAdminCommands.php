<?php

namespace App\Commerce\Console;

use App\Commerce\Accounts\AccountSync;
use App\Commerce\Billing\Plans;
use App\Commerce\Commerce;
use App\Platform\Audit\Audit;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Organizations\Organization;
use App\Platform\Products\ProductCommand;
use Illuminate\Console\Command;
use Throwable;

/**
 * Executa o que o Foxy Admin pediu para lojas do Commerce (docs/08, seção 3.1). Mesmo app: lê os comandos
 * direto, sem a API que o Foxy Studio usa, mas com o mesmo ciclo (pendente → feito/falhou, com resultado).
 */
class RunAdminCommands extends Command
{
    protected $signature = 'commerce:comandos';

    protected $description = 'Executa as ações do Foxy Admin nas lojas do Foxy Commerce';

    public function handle(ContextStore $contexts): int
    {
        $commands = ProductCommand::with('account')
            ->where('integration_id', Commerce::integration()->getKey())
            ->where('status', 'pendente')
            ->orderBy('created_at')->limit(50)->get();

        foreach ($commands as $command) {
            $command->update(['status' => 'enviado', 'picked_at' => now()]);
            $organization = Organization::where('integration_id', $command->integration_id)
                ->where('external_ref', $command->account?->external_ref)->first();

            try {
                [$ok, $message] = $organization
                    ? $contexts->run(ExecutionContext::forSystem($organization, 'foxy_admin'), fn () => $this->apply($command, $organization))
                    : [false, 'Loja não encontrada no Commerce.'];
            } catch (Throwable $e) {
                report($e);
                [$ok, $message] = [false, 'Erro ao executar. Veja o log do servidor.'];
            }

            $command->update(['status' => $ok ? 'feito' : 'falhou', 'result' => $message, 'done_at' => now()]);
            if ($organization) {
                AccountSync::sync($organization->fresh());
            }
        }

        return self::SUCCESS;
    }

    /** @return array{0: bool, 1: string} */
    private function apply(ProductCommand $command, Organization $organization): array
    {
        $subscription = Plans::subscription($organization);
        $payload = $command->payload ?? [];

        $result = match ($command->type) {
            'account.extend_trial' => $this->extendTrial($subscription, (int) ($payload['days'] ?? 0)),
            'account.suspend' => tap([true, 'Loja suspensa.'], fn () => $organization->update(['status' => 'suspended'])),
            'account.unsuspend' => tap([true, 'Loja reativada.'], fn () => $organization->update(['status' => 'active'])),
            'account.founder' => $this->founder($subscription, ! empty($payload['active'])),
            'account.fox_credits' => [false, 'A raposa ainda não chegou ao Foxy Commerce.'],
            'support.reply' => [false, 'O Foxy Commerce ainda não tem chamados de suporte.'],
            default => [false, 'Ação desconhecida.'],
        };

        Audit::record('commerce.admin.'.$command->type, $result[0] ? 'ok' : 'denied', $organization, ['command' => $command->id]);

        return $result;
    }

    private function extendTrial($subscription, int $days): array
    {
        if (! $subscription || $days < 1) {
            return [false, 'Sem assinatura ou número de dias inválido.'];
        }
        if ($subscription->isPaying()) {
            return [false, 'A loja já paga pelo Stripe: o teste não se aplica.'];
        }

        $from = $subscription->trial_ends_at?->isFuture() ? $subscription->trial_ends_at : now();
        $subscription->update(['trial_ends_at' => $from->copy()->addDays($days), 'status' => 'trialing']);

        return [true, 'Teste vai até '.$subscription->trial_ends_at->format('d/m/Y').'.'];
    }

    private function founder($subscription, bool $active): array
    {
        if (! $subscription) {
            return [false, 'Sem assinatura.'];
        }

        $subscription->update(['founder_at' => $active ? ($subscription->founder_at ?? now()) : null]);

        return [true, $active ? 'Parceiro Fundador ativado: uso completo sem cobrança.' : 'Parceiro Fundador desativado.'];
    }
}
