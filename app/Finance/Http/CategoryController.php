<?php

namespace App\Finance\Http;

use App\Finance\Access;
use App\Finance\Categories\Category;
use App\Finance\Insights\Export;
use App\Finance\Transactions\Transaction;
use App\Platform\Audit\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Categorias da carteira (2 níveis). A lista pronta já vem criada; a pessoa cria, renomeia, muda de grupo,
 * esconde (some dos formulários, os lançamentos continuam) e junta duas (os lançamentos passam para a outra).
 */
class CategoryController
{
    public function index(Request $request): View|Response
    {
        $counts = Transaction::query()->whereNotNull('category_id')->selectRaw('category_id, count(*) as total')->groupBy('category_id')->pluck('total', 'category_id');

        if ($format = Export::requested($request)) {
            $rows = Category::with('parent')->get()->sortBy(fn ($c) => [$c->kind, $c->fullName()])
                ->map(fn (Category $c) => [Category::KINDS[$c->kind], $c->parent?->name ?? $c->name, $c->parent ? $c->name : null, (int) ($counts[$c->id] ?? 0), $c->hidden_at ? 'Escondida' : 'Em uso']);

            return (new Export('Categorias'))
                ->section(null, ['Tipo' => 'text', 'Categoria' => 'text', 'Subcategoria' => 'text', 'Lançamentos' => 'int', 'Situação' => 'text'], $rows)
                ->download($format);
        }

        return view('finance.categories', [
            'trees' => collect(Category::KINDS)->map(fn ($label, $kind) => Category::tree($kind)),
            'counts' => $counts,
            'roots' => Category::query()->whereNull('parent_id')->orderBy('name')->get(),
            'all' => Category::with('parent')->get()->sortBy(fn ($c) => $c->fullName()),
            'canManage' => Access::allows($request->attributes->get('finance.membership'), 'finance.accounts.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'kind' => ['required', Rule::in(array_keys(Category::KINDS))],
            'parent_id' => ['nullable', 'string', 'max:30'],
        ], [], ['name' => 'nome']);
        $parent = $this->parent($data['parent_id'] ?? null, $data['kind']);

        $category = Category::create(['name' => trim($data['name']), 'kind' => $data['kind'], 'parent_id' => $parent?->id]);
        Audit::record('finance.category.created', subject: $category);

        return back()->with('status', "Categoria \"{$category->fullName()}\" criada.");
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'parent_id' => ['nullable', 'string', 'max:30'],
        ], [], ['name' => 'nome']);
        $parent = $this->parent($data['parent_id'] ?? null, $category->kind);
        if ($parent && ($parent->id === $category->id || $category->children()->exists())) {
            throw ValidationException::withMessages(['parent_id' => 'Uma categoria que tem subcategorias não pode ficar dentro de outra.']);
        }

        $category->update(['name' => trim($data['name']), 'parent_id' => $parent?->id]);
        Audit::record('finance.category.updated', subject: $category);

        return back()->with('status', 'Categoria salva.');
    }

    public function hide(Request $request, Category $category): RedirectResponse
    {
        $hide = $request->boolean('hide');
        $ids = $category->children()->pluck('id')->push($category->id);
        Category::whereIn('id', $ids)->update(['hidden_at' => $hide ? now() : null, 'updated_at' => now()]);
        Audit::record($hide ? 'finance.category.hidden' : 'finance.category.shown', subject: $category);

        return back()->with('status', $hide ? "\"{$category->name}\" escondida dos formulários. Os lançamentos continuam com ela." : "\"{$category->name}\" voltou para os formulários.");
    }

    /** Junta: os lançamentos (e as subcategorias) passam para a outra, e esta deixa de existir. */
    public function merge(Request $request, Category $category): RedirectResponse
    {
        $data = $request->validate(['target_id' => ['required', 'string', 'max:30']], [], ['target_id' => 'categoria']);
        $target = Category::find($data['target_id']);
        if (! $target || $target->id === $category->id || $target->kind !== $category->kind || $target->parent_id === $category->id) {
            throw ValidationException::withMessages(['target_id' => 'Escolha outra categoria do mesmo tipo (e que não esteja dentro desta).']);
        }

        $moved = DB::transaction(function () use ($category, $target) {
            $moved = Transaction::withTrashed()->where('category_id', $category->id)->update(['category_id' => $target->id, 'updated_at' => now()]);
            $category->children()->update(['parent_id' => $target->parent_id ?? $target->id, 'updated_at' => now()]);
            $category->delete();

            return $moved;
        });
        Audit::record('finance.category.merged', subject: $target, metadata: ['from' => $category->name, 'moved' => $moved]);

        return back()->with('status', "\"{$category->name}\" juntada em \"{$target->fullName()}\" ({$moved} lançamento(s) passaram para ela).");
    }

    private function parent(?string $id, string $kind): ?Category
    {
        if (! $id) {
            return null;
        }
        $parent = Category::whereNull('parent_id')->find($id);
        if (! $parent || $parent->kind !== $kind) {
            throw ValidationException::withMessages(['parent_id' => 'Escolha um grupo do mesmo tipo.']);
        }

        return $parent;
    }
}
