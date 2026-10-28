<?php

namespace App\Ai\Console;

use App\Ai\Conversations\Conversation;
use App\Platform\Audit\Audit;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Organizations\Organization;
use App\Platform\Organizations\OrganizationScope;
use Illuminate\Console\Command;

/**
 * Retenção de 30 dias (decisão B5): apaga conversas vencidas e suas mensagens, em todas as organizações.
 */
class PurgeExpiredConversations extends Command
{
    protected $signature = 'foxy:ai:purge-conversations';

    protected $description = 'Apaga conversas da Foxy AI que passaram do prazo de retenção';

    public function handle(ContextStore $contexts): int
    {
        $expired = Conversation::withoutGlobalScope(OrganizationScope::class)
            ->where('expires_at', '<', now())
            ->selectRaw('organization_id, count(*) as total')
            ->groupBy('organization_id')
            ->pluck('total', 'organization_id');

        foreach ($expired as $organizationId => $total) {
            $organization = Organization::find($organizationId);

            $contexts->run(ExecutionContext::forSystem($organization), function () use ($total) {
                Conversation::where('expires_at', '<', now())->each(fn (Conversation $c) => $c->delete());
                Audit::record('ai.conversations_purged', 'ok', null, ['count' => (int) $total]);
            });
        }

        $this->info('Conversas apagadas: '.$expired->sum());

        return self::SUCCESS;
    }
}
