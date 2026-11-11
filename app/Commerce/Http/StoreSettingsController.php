<?php

namespace App\Commerce\Http;

use App\Commerce\Accounts\AccountSync;
use App\Commerce\Catalog\Money;
use App\Platform\Audit\Audit;
use App\Platform\Context\ExecutionContext;
use App\Platform\Files\File;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Configuração da loja própria (dono e gerente): Pix do lojista, WhatsApp, entrega, cor, logo e publicar.
 */
class StoreSettingsController
{
    public function edit(Request $request): View
    {
        return view('commerce.store-settings', ['settings' => $request->attributes->get('commerce.store')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $store = $request->attributes->get('commerce.store');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'mode' => ['nullable', 'in:loja,catalogo'],
            'hide_sold_out' => ['nullable', 'boolean'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
            'about' => ['nullable', 'string', 'max:2000'],
            'primary_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'pix_key' => ['nullable', 'string', 'max:77'],
            'pix_name' => ['nullable', 'string', 'max:25'],
            'pix_city' => ['nullable', 'string', 'max:15'],
            'payment_instructions' => ['nullable', 'string', 'max:1000'],
            'delivery.pickup' => ['nullable', 'boolean'],
            'delivery.pickup_address' => ['nullable', 'string', 'max:255'],
            'delivery.local' => ['nullable', 'boolean'],
            'delivery.local_fee' => ['nullable', 'string', 'max:20'],
            'delivery.local_area' => ['nullable', 'string', 'max:255'],
            'delivery.shipping' => ['nullable', 'boolean'],
            'delivery.shipping_fee' => ['nullable', 'string', 'max:20'],
            'delivery.shipping_note' => ['nullable', 'string', 'max:255'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ], [], ['name' => 'nome da loja', 'pix_name' => 'nome do recebedor', 'pix_city' => 'cidade do recebedor', 'primary_color' => 'cor']);

        $d = $data['delivery'] ?? [];
        $store->fill([
            'name' => $data['name'], 'mode' => $data['mode'] ?? $store->mode ?? 'loja', 'hide_sold_out' => (bool) ($data['hide_sold_out'] ?? false), 'whatsapp' => $data['whatsapp'] ?? null, 'about' => $data['about'] ?? null, 'primary_color' => $data['primary_color'] ?? null,
            'pix_key' => isset($data['pix_key']) ? trim($data['pix_key']) : null, 'pix_name' => $data['pix_name'] ?? null, 'pix_city' => $data['pix_city'] ?? null,
            'payment_instructions' => $data['payment_instructions'] ?? null,
            'delivery' => [
                'pickup' => (bool) ($d['pickup'] ?? false), 'pickup_address' => $d['pickup_address'] ?? null,
                'local' => (bool) ($d['local'] ?? false), 'local_fee_cents' => Money::toCents($d['local_fee'] ?? null) ?? 0, 'local_area' => $d['local_area'] ?? null,
                'shipping' => (bool) ($d['shipping'] ?? false), 'shipping_fee_cents' => Money::toCents($d['shipping_fee'] ?? null) ?? 0, 'shipping_note' => $d['shipping_note'] ?? null,
            ],
        ]);

        if ($request->hasFile('logo')) {
            $old = $store->logo;
            $store->logo_file_id = File::store($request->file('logo'), 'commerce.store_logo', config('commerce.images_disk'), 'commerce/logo')->id;
            $old?->purge();
        }

        // Mudou para um modo que não fecha (ex.: loja com carrinho sem Pix): sai do ar até completar.
        $problem = $store->isPublished() ? $store->publishProblem() : null;
        if ($problem) {
            $store->published_at = null;
        }
        $store->save();
        AccountSync::sync(ExecutionContext::current()->organization);
        Audit::record('commerce.store.updated', subject: $store);

        return back()->with('status', $problem ? 'Loja salva e tirada do ar. '.$problem : 'Loja salva.');
    }

    public function publish(Request $request): RedirectResponse
    {
        $store = $request->attributes->get('commerce.store');
        $publish = $request->boolean('publish');

        if ($publish && $problem = $store->publishProblem()) {
            throw ValidationException::withMessages(['publish' => $problem]);
        }

        $store->update(['published_at' => $publish ? ($store->published_at ?? now()) : null]);
        Audit::record($publish ? 'commerce.store.published' : 'commerce.store.unpublished', subject: $store);

        return back()->with('status', $publish ? 'Loja publicada.' : 'Loja tirada do ar.');
    }
}
