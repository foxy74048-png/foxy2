<?php

namespace App\Commerce\Http;

use App\Commerce\Catalog\Money;
use App\Commerce\Consignment\Consignment;
use App\Commerce\Finance\Receivable;
use App\Commerce\Orders\Customer;
use App\Commerce\Storefront\PixCode;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Link da maleta (sem login): a cliente ou revendedora vê as maletas que estão com ela, quanto ganha se vender
 * tudo, os acertos anteriores e o que falta pagar — com o Pix da loja, quando houver. Só leitura; o link é um
 * código secreto da pessoa, que a loja troca quando quiser. Não depende da loja estar publicada.
 */
class ResellerPortalController
{
    public function show(Request $request, string $slug, string $token): View
    {
        $store = $request->attributes->get('storefront.store');
        $customer = Customer::where('portal_token', $token)->firstOrFail();
        $consignments = Consignment::with('items', 'order')->where('customer_id', $customer->id)->latest('sent_on')->latest('created_at')->get();
        abort_if($consignments->isEmpty(), 404);

        $open = $consignments->where('status', 'aberta')->values();
        $settled = $consignments->where('status', 'acertada')->values();
        $receivables = Receivable::open()->where('customer_id', $customer->id)->orderBy('due_date')->get();
        $next = $receivables->first();
        $pix = $next && $store->pixReady() ? PixCode::payload($store->pix_key, $store->pix_name, $store->pix_city, $next->amount_cents, 'MALETA') : null;

        return view('storefront.portal', [
            'customer' => $customer,
            'open' => $open,
            'settled' => $settled,
            'receivables' => $receivables,
            'next' => $next,
            'pix' => $pix,
            'qr' => $pix ? PixCode::qrSvg($pix) : null,
            'potential' => (int) $open->sum(fn (Consignment $c) => round($c->valueCents() * $c->commission_percent / 100)),
            'earned' => (int) $settled->sum(fn (Consignment $c) => $c->order?->discount_cents ?? 0),
            'chat' => $store->whatsappLink('Oi! Aqui é '.strtok((string) $customer->name, ' ').', sobre a minha maleta'.($open->isNotEmpty() ? ' #'.$open->first()->number : '').': '),
            'money' => fn (?int $c) => Money::format((int) $c),
        ]);
    }
}
