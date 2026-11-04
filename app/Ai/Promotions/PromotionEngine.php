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
     * external_ref. Conta do produto que ainda não usou a raposa ganha o cliente aqui (mesmo external_ref que o
     * produto usa no 1º uso), para a promoção chegar a todas as contas — não só a quem já conversou.
     *
     * @return Collection<int, Organization>
     */
    public function eligible(AiPromotion $promotion): Collection
    {
        $accounts = ProductAccount::query()
            ->when($promotion->integration_id, fn ($q) => $q->where('integration_id', $promotion->integration_id))
            ->when($promotion->audience === 'contas', fn ($q) => $q->whereIn('external_ref', $promotion->account_refs ?? []))
            ->when($promotion->audience !== 'contas', fn ($q) => $q->whereIn('status', ProductAccount::LIVE))
            ->when($promotion->audience !== 'contas' && $promotion->plans, fn ($q) => $q->whereIn('plan', $promotion->plans))
            ->when($promotion->audience === 'novas_contas', fn ($q) => $q->where('signed_up_at', '>=', now()->subDays($promotion->new_account_days ?: 30)))
            ->get();

        $organizations = Organization::query()
            ->whereNotNull('external_ref')
            ->when($promotion->integration_id, fn ($q) => $q->where('integration_id', $promotion->integration_id))
            ->get()
            ->keyBy(fn (Organization $o) => $o->integration_id.'|'.$o->external_ref);

        $eligible = $accounts->map(fn (ProductAccount $a) => $organizations->get($a->integration_id.'|'.$a->external_ref)
            ?? Organization::create(['integration_id' => $a->integration_id, 'name' => $a->name, 'external_ref' => $a->external_ref]));

        // "Contas escolhidas" também vale para cliente da Foxy AI sem conta no produto (ex.: o de demonstração).
        if ($promotion->audience === 'contas') {
            $eligible = $eligible->merge($organizations->filter(fn (Organization $o) => in_array($o->external_ref, $promotion->account_refs ?? [], true)));
        }

        return $eligible->filter(fn (Organization $o) => $o->status === 'active')->unique(fn (Organization $o) => $o->getKey())->values();
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
