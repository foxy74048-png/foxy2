<?php

namespace App\Commerce\Http;

use App\Commerce\Access;
use App\Commerce\Http\Middleware\ResolveStore;
use App\Commerce\Stores\OpenStore;
use App\Commerce\Stores\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Quem trabalha em mais de uma loja escolhe em qual está; quem não tem loja pode abrir uma.
 */
class StoreController
{
    public function choose(Request $request): View|RedirectResponse
    {
        $memberships = ResolveStore::membershipsOf($request->user()->getKey());

        if ($memberships->isEmpty()) {
            return redirect()->route('commerce.stores.new');
        }

        $stores = Store::withoutGlobalScopes()->whereIn('organization_id', $memberships->pluck('organization_id'))->get()->keyBy('organization_id');

        return view('commerce.stores.choose', [
            'options' => $memberships->map(fn ($m) => ['membership' => $m, 'store' => $stores->get($m->organization_id), 'role' => Access::label($m->role)]),
        ]);
    }

    public function switch(Request $request): RedirectResponse
    {
        $data = $request->validate(['organization_id' => ['required', 'string']]);
        $membership = ResolveStore::membershipsOf($request->user()->getKey())->firstWhere('organization_id', $data['organization_id']);
        abort_unless($membership, 404);

        $request->session()->put(ResolveStore::SESSION_KEY, $membership->organization_id);

        return redirect()->route('commerce.dashboard');
    }

    public function create(Request $request): View
    {
        return view('commerce.stores.new', ['hasStores' => ResolveStore::membershipsOf($request->user()->getKey())->isNotEmpty()]);
    }

    public function store(Request $request, OpenStore $stores): RedirectResponse
    {
        abort_unless(config('commerce.signup_open') || $request->user()->isStaff(), 404);

        $data = $request->validate([
            'store_name' => ['required', 'string', 'min:2', 'max:80'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
        ], [], ['store_name' => 'nome da loja']);

        $store = $stores->open($request->user(), $data['store_name'], $data['whatsapp'] ?? null);
        $request->session()->put(ResolveStore::SESSION_KEY, $store->organization_id);

        return redirect()->route('commerce.dashboard')->with('status', 'Loja criada.');
    }
}
