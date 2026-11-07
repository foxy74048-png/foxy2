<?php

namespace App\Commerce\Http;

use App\Commerce\Catalog\Category;
use App\Platform\Context\ExecutionContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CategoryController
{
    public function index(): View
    {
        return view('commerce.categories', [
            'categories' => Category::with('parent')->withCount('products')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Category::create($this->validated($request));

        return back()->with('status', 'Categoria criada.');
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $data = $this->validated($request);
        if (($data['parent_id'] ?? null) === $category->id) {
            throw ValidationException::withMessages(['name' => 'Uma categoria não pode estar dentro dela mesma.']);
        }
        $category->update($data);

        return back()->with('status', 'Categoria salva.');
    }

    public function destroy(Category $category): RedirectResponse
    {
        if ($category->products()->exists() || Category::where('parent_id', $category->id)->exists()) {
            throw ValidationException::withMessages(['name' => 'Só dá para apagar categoria sem produtos e sem subcategorias.']);
        }
        $category->delete();

        return back()->with('status', 'Categoria apagada.');
    }

    private function validated(Request $request): array
    {
        $organizationId = ExecutionContext::current()->organization->getKey();

        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            // Só um nível de subcategoria: a categoria-mãe não pode ter mãe.
            'parent_id' => ['nullable', Rule::exists('commerce_categories', 'id')->where('organization_id', $organizationId)->whereNull('parent_id')],
        ], [], ['name' => 'nome', 'parent_id' => 'categoria-mãe']);
    }
}
