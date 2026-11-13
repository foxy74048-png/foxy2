<?php

namespace App\Commerce\Http;

use App\Commerce\Catalog\Category;
use App\Commerce\Catalog\Product;
use App\Commerce\Catalog\Selection;
use App\Commerce\Insights\Export;
use App\Commerce\Inventory\StockService;
use App\Commerce\Stores\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Seleções do catálogo: a lojista marca algumas peças e manda às clientes um link só com elas (estoque de agora,
 * pedido pelo WhatsApp da loja). Dá para editar e desativar (o link para de abrir).
 */
class SelectionController
{
    public function __construct(private StockService $stock) {}

    public function index(Request $request): View|Response
    {
        $filters = $request->validate(['situacao' => ['nullable', Rule::in(['ativas', 'desativadas', 'todas'])]]);
        $status = $filters['situacao'] ?? 'ativas';
        $query = Selection::query()->when($status !== 'todas', fn ($q) => $q->where('active', $status === 'ativas'))->latest('updated_at');

        if ($format = Export::requested($request)) {
            return (new Export('Seleções para clientes'))
                ->filters(['Situação' => ['ativas' => 'Ativas', 'desativadas' => 'Desativadas', 'todas' => 'Todas'][$status]])
                ->section(null, ['Seleção' => 'text', 'Peças' => 'int', 'Só com estoque' => 'text', 'Atualizada em' => 'date', 'Situação' => 'text', 'Link' => 'text'],
                    $query->get()->map(fn (Selection $s) => [$s->title, count($s->product_ids ?? []), $s->only_in_stock ? 'Sim' : 'Não', $s->updated_at, $s->active ? 'Ativa' : 'Desativada', $this->url($s)]))
                ->download($format);
        }

        return view('commerce.selections.index', [
            'selections' => $query->paginate(30)->withQueryString(),
            'filters' => $filters + ['situacao' => $status],
            'urls' => fn (Selection $s) => $this->url($s),
        ]);
    }

    public function create(Request $request): View
    {
        $data = $request->validate(['produtos' => ['nullable', 'array', 'max:500'], 'produtos.*' => ['string', 'max:30']]);

        return $this->form(new Selection(['title' => '', 'only_in_stock' => true, 'product_ids' => array_values($data['produtos'] ?? [])]));
    }

    public function store(Request $request): RedirectResponse
    {
        $selection = Selection::create($this->validated($request) + ['active' => true]);

        return redirect()->route('commerce.selections.show', $selection)->with('status', 'Seleção criada. Mande o link para as suas clientes.');
    }

    public function show(Request $request, Selection $selection): View
    {
        return $this->form($selection);
    }

    public function update(Request $request, Selection $selection): RedirectResponse
    {
        $selection->update($this->validated($request));

        return redirect()->route('commerce.selections.show', $selection)->with('status', 'Seleção salva. O link continua o mesmo.');
    }

    public function toggle(Request $request, Selection $selection): RedirectResponse
    {
        $selection->update(['active' => ! $selection->active]);

        return back()->with('status', $selection->active ? 'Seleção reativada: o link volta a abrir.' : 'Seleção desativada: o link não abre mais.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'only_in_stock' => ['nullable', 'boolean'],
            'products' => ['required', 'array', 'min:1', 'max:500'],
            'products.*' => ['string', 'max:30'],
        ], ['products.required' => 'Marque pelo menos uma peça.', 'products.min' => 'Marque pelo menos uma peça.'], ['title' => 'nome da seleção']);
        // Só produtos desta loja (o escopo da organização filtra), na ordem marcada.
        $valid = Product::whereIn('id', $data['products'])->where('status', '!=', 'archived')->pluck('id')->all();
        $ids = array_values(array_unique(array_intersect($data['products'], $valid)));
        if ($ids === []) {
            throw ValidationException::withMessages(['products' => 'Marque pelo menos uma peça.']);
        }

        return ['title' => trim($data['title']), 'only_in_stock' => $request->boolean('only_in_stock'), 'product_ids' => $ids];
    }

    private function form(Selection $selection): View
    {
        $products = Product::with(['images.file', 'category', 'variants' => fn ($q) => $q->where('status', 'active')])->where('status', 'active')->orderBy('name')->get();
        $available = $this->stock->availability($products->flatMap->variants);
        $chosen = $selection->product_ids ?? [];
        // As marcadas primeiro, na ordem em que foram escolhidas.
        $products = $products->sortBy(fn (Product $p) => [in_array($p->id, $chosen, true) ? array_search($p->id, $chosen, true) : PHP_INT_MAX, $p->name])->values();

        return view('commerce.selections.form', [
            'selection' => $selection,
            'products' => $products,
            'chosen' => $chosen,
            'stockOf' => fn (Product $p) => (int) $p->variants->sum(fn ($v) => max(0, $available[$v->id] ?? 0)),
            'categories' => Category::with('parent')->orderBy('name')->get(),
            'store' => Store::first(),
            'url' => $selection->exists ? $this->url($selection) : null,
        ]);
    }

    private function url(Selection $selection): string
    {
        return route('commerce.storefront.selection', [Store::value('slug'), $selection->token]);
    }
}
