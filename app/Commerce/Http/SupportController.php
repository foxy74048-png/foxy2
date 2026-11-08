<?php

namespace App\Commerce\Http;

use App\Commerce\Accounts\AccountSync;
use App\Platform\Audit\Audit;
use App\Platform\Context\ExecutionContext;
use App\Platform\Products\ProductAccount;
use App\Platform\Products\ProductTicket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Suporte da loja: o chamado vai direto para a central do Foxy Admin (/admin/suporte) e a resposta volta pelo
 * comando support.reply (commerce:comandos). ProductTicket não tem escopo: sempre filtrar pela conta da loja.
 */
class SupportController
{
    public const CATEGORIES = ['duvida' => 'Dúvida de uso', 'problema' => 'Algo não funciona', 'marketplace' => 'Marketplace / anúncios', 'financeiro' => 'Assinatura e cobrança', 'sugestao' => 'Sugestão'];

    public function index(): View
    {
        $account = $this->account();

        return view('commerce.support', [
            'tickets' => ProductTicket::where('integration_id', $account->integration_id)->where('product_account_id', $account->id)->latest('opened_at')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:150'],
            'category' => ['required', Rule::in(array_keys(self::CATEGORIES))],
            'message' => ['required', 'string', 'max:5000'],
        ], [], ['subject' => 'assunto', 'category' => 'tipo', 'message' => 'mensagem']);

        $account = $this->account();
        $ticket = ProductTicket::create([
            'integration_id' => $account->integration_id,
            'product_account_id' => $account->id,
            'external_id' => 'c-'.Str::lower((string) Str::ulid()),
            'subject' => $data['subject'],
            'category' => self::CATEGORIES[$data['category']],
            'priority' => $data['category'] === 'problema' ? 'alta' : 'normal',
            'status' => 'open',
            'message' => "De: {$request->user()->name} ({$request->user()->email})\n\n".$data['message'],
            'opened_at' => now(),
        ]);
        Audit::record('commerce.support.opened', subject: $ticket);

        return back()->with('status', 'Chamado aberto. A equipe Foxy responde por aqui e por e-mail.');
    }

    /** Conta da loja no Foxy Admin (criada na hora se ainda não existir). */
    private function account(): ProductAccount
    {
        $organization = ExecutionContext::current()->organization;

        return ProductAccount::where('integration_id', $organization->integration_id)->where('external_ref', $organization->external_ref)->first()
            ?? AccountSync::sync($organization);
    }
}
