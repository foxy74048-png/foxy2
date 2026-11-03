<?php

namespace App\Admin\Http;

use App\Ai\Promotions\AiPromotion;
use App\Ai\Promotions\AiPromotionGrant;
use App\Ai\Promotions\PromotionEngine;
use App\Ai\Usage\UsageReport;
use App\Platform\Integrations\Integration;
use App\Platform\Organizations\Organization;
use App\Platform\Products\ProductAccount;
use App\Platform\Products\ProductSnapshot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Foxy Admin (docs/08): o administrativo da empresa, por cima de todos os produtos. Só a equipe Foxy (login do
 * console). Lê o que os produtos enviam ao Core (product_accounts) e os dados da Foxy AI; nunca o banco deles.
 */
class AdminController
{
    public function dashboard(): View
    {
        $month = now()->format('Y-m');
        $integrations = Integration::orderBy('name')->get();
        $organizations = Organization::whereNotNull('integration_id')->get();
        $usage = UsageReport::forOrganizations($organizations->pluck('id')->all(), $month);

        $products = $integrations->map(function (Integration $integration) use ($organizations, $usage) {
            $accounts = ProductAccount::where('integration_id', $integration->id)->get();
            $orgIds = $organizations->where('integration_id', $integration->id)->pluck('id');
            $ai = $usage->only($orgIds->all());

            return [
                'integration' => $integration,
                'snapshot' => ProductSnapshot::where('integration_id', $integration->id)->latest('taken_at')->first(),
                'by_status' => $accounts->countBy('status'),
                'mrr' => (float) $accounts->where('status', 'active')->sum('mrr'),
                'ai_clients' => $orgIds->count(),
                'ai_messages' => $ai->sum('messages'),
                'ai_credits' => $ai->sum('credits'),
                'ai_cost' => $ai->sum('cost_usd'),
            ];
        });

        $topAi = $usage->filter(fn ($row) => $row['credits'] > 0)->sortByDesc('credits')->take(10)
            ->map(fn ($row, $orgId) => $row + ['organization' => $organizations->firstWhere('id', $orgId)]);

        return view('admin.dashboard', [
            'products' => $products,
            'topAi' => $topAi,
            'aiTotals' => ['messages' => $usage->sum('messages'), 'credits' => $usage->sum('credits'), 'cost' => $usage->sum('cost_usd')],
            'promotions' => [
                'running' => AiPromotion::where('status', 'ativa')->get()->filter->isRunning()->count(),
                'granted' => (int) AiPromotionGrant::where('created_at', '>=', now()->startOfMonth())->sum('credits'),
                'clients' => AiPromotionGrant::where('created_at', '>=', now()->startOfMonth())->distinct('organization_id')->count('organization_id'),
            ],
            'month' => $month,
            'attention' => [
                'problems' => ProductAccount::where('status', '!=', 'removed')->whereNotNull('problems')->get()->filter(fn ($a) => ! empty($a->problems))->take(8),
                'tickets' => \App\Platform\Products\ProductTicket::where('status', 'open')->count(),
                'idlePaying' => ProductAccount::where('status', 'active')->where('mrr', '>', 0)->whereIn('health', ['idle', 'never'])->count(),
                'trialsEnding' => ProductAccount::where('status', 'trial')->whereBetween('trial_ends_at', [now(), now()->addDays(3)])->count(),
                'failedCommands' => \App\Platform\Products\ProductCommand::where('status', 'falhou')->where('created_at', '>=', now()->subDays(7))->count(),
            ],
        ]);
    }

    public function accounts(Request $request): View
    {
        $filters = $request->validate([
            'produto' => ['nullable', 'string', 'max:40'],
            'situacao' => ['nullable', Rule::in(array_keys(ProductAccount::STATUSES))],
            'busca' => ['nullable', 'string', 'max:80'],
        ]);

        $accounts = ProductAccount::with('integration')
            ->when($filters['produto'] ?? null, fn ($q, $id) => $q->where('integration_id', $id))
            ->when($filters['situacao'] ?? null, fn ($q, $s) => $q->where('status', $s), fn ($q) => $q->where('status', '!=', 'removed'))
            ->when($filters['busca'] ?? null, fn ($q, $term) => $q->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%'))
            ->orderByDesc('mrr')->orderBy('name')
            ->paginate(50)->withQueryString();

        return view('admin.accounts', [
            'accounts' => $accounts,
            'aiCredits' => $this->aiCreditsFor($accounts->getCollection()),
            'integrations' => Integration::orderBy('name')->get(),
            'filters' => $filters,
        ]);
    }

    public function promotions(): View
    {
        $promotions = AiPromotion::with('integration')->withCount('grants')->withSum('grants', 'credits')->latest()->get();

        return view('admin.promotions', [
            'promotions' => $promotions,
            'integrations' => Integration::orderBy('name')->get(),
            'accounts' => ProductAccount::with('integration')->where('status', '!=', 'removed')->orderBy('name')->get(),
        ]);
    }

    public function storePromotion(Request $request, PromotionEngine $engine): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'integration_id' => ['nullable', Rule::exists('integrations', 'id')],
            'audience' => ['required', Rule::in(array_keys(AiPromotion::AUDIENCES))],
            'recurrence' => ['required', Rule::in(array_keys(AiPromotion::RECURRENCES))],
            'credits' => ['required', 'integer', 'min:1', 'max:100000'],
            'new_account_days' => ['nullable', 'required_if:audience,novas_contas', 'integer', 'min:1', 'max:365'],
            'plans' => ['nullable', 'string', 'max:300'],
            'account_refs' => ['nullable', 'required_if:audience,contas', 'array'],
            'account_refs.*' => ['string', 'max:120'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $plans = array_values(array_filter(array_map('trim', explode(',', (string) ($data['plans'] ?? '')))));

        $promotion = AiPromotion::create([
            ...collect($data)->except(['plans', 'account_refs'])->all(),
            'plans' => $plans ?: null,
            'account_refs' => $data['audience'] === 'contas' ? array_values($data['account_refs'] ?? []) : null,
            'new_account_days' => $data['audience'] === 'novas_contas' ? $data['new_account_days'] : null,
            'status' => 'ativa',
            'created_by' => $request->user()->id,
        ]);

        $made = $engine->apply($promotion);

        return redirect()->route('admin.promotions')->with('status', "Promoção \"{$promotion->name}\" criada. Entregue agora para {$made} cliente(s); as próximas entregas são automáticas.");
    }

    public function promotionStatus(Request $request, AiPromotion $promotion, PromotionEngine $engine): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(AiPromotion::STATUSES))]]);
        $promotion->update($data);
        $made = $data['status'] === 'ativa' ? $engine->apply($promotion) : 0;

        return redirect()->route('admin.promotions')->with('status', "Promoção \"{$promotion->name}\": ".AiPromotion::STATUSES[$data['status']].($made ? " — {$made} entrega(s) feitas agora." : '.'));
    }

    /**
     * Créditos de IA gastos no mês por conta de produto (pelo cliente da Foxy AI com o mesmo external_ref).
     *
     * @return array<string, float> id da conta → créditos
     */
    private function aiCreditsFor(Collection $accounts): array
    {
        $orgs = Organization::whereIn('external_ref', $accounts->pluck('external_ref'))->get()
            ->keyBy(fn (Organization $o) => $o->integration_id.'|'.$o->external_ref);
        $usage = UsageReport::forOrganizations($orgs->pluck('id')->all(), now()->format('Y-m'));

        return $accounts->mapWithKeys(function (ProductAccount $account) use ($orgs, $usage) {
            $org = $orgs->get($account->integration_id.'|'.$account->external_ref);

            return [$account->id => $org ? ($usage[$org->id]['credits'] ?? 0) : null];
        })->all();
    }
}
