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
    public function index(Request $request): View|\Symfony\Component\HttpFoundation\Response
    {
        $kind = isset(AiInsight::KINDS[$request->query('tipo')]) ? $request->query('tipo') : 'ideia';
        $status = $request->query('situacao', 'nova');
        $status = $status === 'todas' || isset(AiInsight::STATUSES[$status]) ? $status : 'nova';

        $query = AiInsight::with('organization.integration')
            ->where('kind', $kind)
            ->when($status !== 'todas', fn ($q) => $q->where('status', $status))
            ->latest();

        if ($format = \App\Platform\Exports\Export::requested($request)) {
            $all = $query->get();
            $names = ProductAccount::whereIn('external_ref', $all->pluck('organization.external_ref')->filter())->get()
                ->keyBy(fn (ProductAccount $a) => $a->integration_id.'|'.$a->external_ref);

            return (new \App\Platform\Exports\Export(AiInsight::KINDS[$kind].' anotadas pela raposa'))
                ->filters(['Situação' => $status === 'todas' ? 'Todas' : AiInsight::STATUSES[$status]])
                ->section(null, ['Quando' => 'datetime', 'O que pediram' => 'text', 'Tela' => 'text:planilha', 'Produto' => 'text', 'Conta' => 'text', 'Situação' => 'text'],
                    $all->map(fn (AiInsight $i) => [$i->created_at->timezone('America/Sao_Paulo'), $i->summary, $i->page, $i->organization?->integration?->name,
                        $names->get($i->organization?->integration_id.'|'.$i->organization?->external_ref)?->name ?? $i->organization?->name, AiInsight::STATUSES[$i->status] ?? $i->status]))
                ->download($format);
        }

        $insights = $query->paginate(40)->withQueryString();

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
