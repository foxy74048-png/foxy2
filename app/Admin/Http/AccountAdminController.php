<?php

namespace App\Admin\Http;

use App\Ai\Plans\AiCreditGrant;
use App\Ai\Plans\AiPlan;
use App\Ai\Promotions\AiPromotion;
use App\Ai\Promotions\AiPromotionGrant;
use App\Ai\Promotions\PromotionEngine;
use App\Ai\Usage\UsageReport;
use App\Platform\Announcements\AnnouncementDelivery;
use App\Platform\Messaging\WhatsAppSender;
use App\Platform\Organizations\Organization;
use App\Platform\Products\ProductAccount;
use App\Platform\Products\ProductCommand;
use App\Platform\Products\ProductTicket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Ficha de uma conta no Foxy Admin: tudo dela num lugar só e as ações. O que mexe no produto (teste, suspensão,
 * Parceiro Fundador, raposa) vira comando que o produto executa; o que é do Core (perguntas de presente, mensagem
 * de ajuda pelo WhatsApp da Foxy) acontece aqui mesmo.
 */
class AccountAdminController
{
    public function show(ProductAccount $account): View
    {
        $organization = $this->organization($account);
        $month = now()->format('Y-m');

        return view('admin.account', [
            'account' => $account->load('integration'),
            'organization' => $organization,
            'ai' => $organization ? UsageReport::forOrganizations([$organization->id], $month)[$organization->id] : null,
            'extraCredits' => $organization ? (int) AiCreditGrant::withoutGlobalScopes()->where('organization_id', $organization->id)->where('period', $month)->sum('credits') : 0,
            'grants' => $organization ? AiPromotionGrant::with('promotion')->where('organization_id', $organization->id)->latest('id')->limit(20)->get() : collect(),
            'deliveries' => AnnouncementDelivery::with('announcement')->where('product_account_id', $account->id)->latest('id')->limit(20)->get(),
            'tickets' => ProductTicket::where('product_account_id', $account->id)->latest('opened_at')->limit(20)->get(),
            'commands' => ProductCommand::where('product_account_id', $account->id)->latest()->limit(30)->get(),
            'sizes' => array_map(fn (AiPlan $p) => $p->credits, AiPlan::all()),
            'whatsappReady' => app(WhatsAppSender::class)->isConfigured(),
        ]);
    }

    /**
     * Ação que o produto executa (estender teste, suspender, Parceiro Fundador, liberar raposa).
     */
    public function command(Request $request, ProductAccount $account): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['account.extend_trial', 'account.suspend', 'account.unsuspend', 'account.founder', 'account.fox_credits'])],
            'days' => ['nullable', 'required_if:type,account.extend_trial', 'integer', 'min:1', 'max:365'],
            'reason' => ['nullable', 'string', 'max:500'],
            'active' => ['nullable', 'boolean'],
            'credits' => ['nullable', 'required_if:type,account.fox_credits', 'integer', 'min:0', 'max:1000000'],
        ]);

        $payload = match ($data['type']) {
            'account.extend_trial' => ['days' => (int) $data['days']],
            'account.suspend' => ['reason' => $data['reason'] ?? null],
            'account.founder' => ['active' => (bool) ($data['active'] ?? false)],
            'account.fox_credits' => ['credits' => (int) $data['credits']],
            default => [],
        };

        $command = ProductCommand::create([
            'integration_id' => $account->integration_id,
            'product_account_id' => $account->id,
            'type' => $data['type'],
            'payload' => $payload,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('status', '"'.$command->describe().'" pedido ao '.$account->integration->name.'. Acontece em até 1 minuto — o resultado aparece no histórico abaixo.');
    }

    /**
     * Perguntas de presente para a raposa (vira uma promoção "uma vez" só desta conta — fica no histórico).
     */
    public function gift(Request $request, ProductAccount $account, PromotionEngine $engine): RedirectResponse
    {
        $data = $request->validate(['credits' => ['required', 'integer', 'min:1', 'max:100000'], 'note' => ['nullable', 'string', 'max:300']]);

        $this->organization($account) ?? Organization::create([
            'integration_id' => $account->integration_id, 'name' => $account->name, 'external_ref' => $account->external_ref,
        ]);

        $promotion = AiPromotion::create([
            'integration_id' => $account->integration_id,
            'name' => 'Presente para '.$account->name,
            'audience' => 'contas',
            'recurrence' => 'uma_vez',
            'credits' => $data['credits'],
            'account_refs' => [$account->external_ref],
            'status' => 'ativa',
            'note' => $data['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);
        $engine->apply($promotion);
        $promotion->update(['status' => 'encerrada']); // presente avulso: entregue, acabou

        return back()->with('status', number_format($data['credits'], 0, ',', '.').' perguntas de presente para '.$account->name.', valendo neste mês (usadas quando a conta tem a raposa ligada).');
    }

    /**
     * Mensagem de ajuda pelo WhatsApp da Foxy (ex.: "seu WhatsApp está desconectado").
     */
    public function help(Request $request, ProductAccount $account, WhatsAppSender $whatsapp): RedirectResponse
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:2000']]);

        if (! $account->whatsapp) {
            return back()->withErrors(['message' => 'Esta conta não tem WhatsApp do dono cadastrado.']);
        }

        $error = $whatsapp->send($account->whatsapp, $data['message']);

        return $error === null
            ? back()->with('status', 'Mensagem de ajuda enviada pelo WhatsApp da Foxy.')
            : back()->withErrors(['message' => 'Não saiu: '.$error]);
    }

    private function organization(ProductAccount $account): ?Organization
    {
        return Organization::where('integration_id', $account->integration_id)->where('external_ref', $account->external_ref)->first();
    }
}
