<?php

namespace App\Ai\Promotions;

use App\Ai\Plans\AiCreditGrant;
use App\Platform\Audit\Audit;
use App\Platform\Context\ContextStore;
use App\Platform\Organizations\Organization;
use App\Platform\Products\ProductAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Entrega as promoções de IA que estão valendo para quem tem direito. Pode rodar quantas vezes quiser: cada
 * promoção entrega uma vez por cliente e período (ai_promotion_grants único). Roda ao salvar uma promoção,
 * quando um produto envia o retrato das contas, quando um cliente novo aparece e todo dia (foxy:promocoes:aplicar).
 */
class PromotionEngine
{
    /**
     * @return int quantas entregas novas foram feitas
     */
    public function apply(?AiPromotion $only = null): int
    {
        $promotions = $only ? collect([$only]) : AiPromotion::where('status', 'ativa')->get();
        $made = 0;

        foreach ($promotions as $promotion) {
            if (! $promotion->isRunning()) {
                continue;
            }

            foreach ($this->eligible($promotion) as $organization) {
                $made += $this->grant($promotion, $organization) ? 1 : 0;
            }
        }

        return $made;
    }

    /**
     * Clientes da Foxy AI que têm direito agora. A conta do produto (product_accounts) e o cliente se ligam pelo
     * external_ref; sem conta conhecida, o cliente só entra em "contas escolhidas".
     *
     * @return Collection<int, Organization>
     */
    public function eligible(AiPromotion $promotion): Collection
    {
        $organizations = Organization::query()
            ->where('status', 'active')
            ->whereNotNull('external_ref')
            ->when($promotion->integration_id, fn ($q) => $q->where('integration_id', $promotion->integration_id))
            ->get();

        if ($promotion->audience === 'contas') {
            $refs = $promotion->account_refs ?? [];

            return $organizations->filter(fn (Organization $o) => in_array($o->external_ref, $refs, true))->values();
        }

        $accounts = ProductAccount::query()
            ->whereIn('integration_id', $organizations->pluck('integration_id')->unique()->values())
            ->whereIn('external_ref', $organizations->pluck('external_ref'))
            ->whereIn('status', ProductAccount::LIVE)
            ->when($promotion->plans, fn ($q) => $q->whereIn('plan', $promotion->plans))
            ->when($promotion->audience === 'novas_contas', fn ($q) => $q->where('signed_up_at', '>=', now()->subDays($promotion->new_account_days ?: 30)))
            ->get()
            ->keyBy(fn (ProductAccount $a) => $a->integration_id.'|'.$a->external_ref);

        return $organizations->filter(fn (Organization $o) => $accounts->has($o->integration_id.'|'.$o->external_ref))->values();
    }

    private function grant(AiPromotion $promotion, Organization $organization): bool
    {
        $period = $promotion->period();

        $created = DB::transaction(function () use ($promotion, $organization, $period) {
            $exists = AiPromotionGrant::where('ai_promotion_id', $promotion->getKey())
                ->where('organization_id', $organization->getKey())
                ->where('period', $period)
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                return false; // já entregue — aplicar de novo não repete
            }

            AiPromotionGrant::create([
                'ai_promotion_id' => $promotion->getKey(),
                'organization_id' => $organization->getKey(),
                'period' => $period,
                'credits' => $promotion->credits,
            ]);

            // Crédito extra da Foxy AI, no mês corrente (brinde pode ser qualquer quantidade).
            AiCreditGrant::create([
                'organization_id' => $organization->getKey(),
                'period' => now()->format('Y-m'),
                'credits' => $promotion->credits,
                'reference' => 'promo-'.$promotion->getKey(),
            ]);

            return true;
        });

        // Quem ganhou fica sempre em ai_promotion_grants; a auditoria do Core só existe dentro de uma chamada de API.
        if ($created && app(ContextStore::class)->has()) {
            Audit::record('ai.promotion_granted', 'ok', null, ['promotion' => $promotion->getKey(), 'organization' => $organization->getKey(), 'credits' => $promotion->credits]);
        }

        return $created;
    }
}
