<?php

namespace App\Admin\Http;

use App\Ai\Insights\AiInsight;
use App\Platform\Organizations\Organization;
use App\Platform\Products\ProductAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Ideias e dúvidas sem resposta que a raposa anotou, de todos os produtos (docs/08, fase 2).
 */
class InsightController
{
    public function index(Request $request): View
    {
        $kind = isset(AiInsight::KINDS[$request->query('tipo')]) ? $request->query('tipo') : 'ideia';
        $status = $request->query('situacao', 'nova');
        $status = $status === 'todas' || isset(AiInsight::STATUSES[$status]) ? $status : 'nova';

        $insights = AiInsight::with('organization.integration')
            ->where('kind', $kind)
            ->when($status !== 'todas', fn ($q) => $q->where('status', $status))
            ->latest()->paginate(40)->withQueryString();

        // Nome da conta no produto (pelo external_ref do cliente da Foxy AI).
        $orgs = $insights->getCollection()->pluck('organization')->filter();
        $accounts = ProductAccount::whereIn('external_ref', $orgs->pluck('external_ref')->filter())->get()
            ->keyBy(fn (ProductAccount $a) => $a->integration_id.'|'.$a->external_ref);

        return view('admin.insights', [
            'insights' => $insights,
            'accountOf' => fn (?Organization $o) => $o ? $accounts->get($o->integration_id.'|'.$o->external_ref) : null,
            'kind' => $kind,
            'status' => $status,
            'newByKind' => AiInsight::where('status', 'nova')->selectRaw('kind, COUNT(*) as total')->groupBy('kind')->pluck('total', 'kind'),
            'counts' => AiInsight::where('kind', $kind)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    public function update(Request $request, AiInsight $insight): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(AiInsight::STATUSES))]]);
        $insight->update($data);

        return back()->with('status', 'Marcado como "'.AiInsight::STATUSES[$data['status']].'".');
    }
}
