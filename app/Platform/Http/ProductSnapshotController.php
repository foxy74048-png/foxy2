<?php

namespace App\Platform\Http;

use App\Ai\Plans\AiCreditGrant;
use App\Ai\Promotions\PromotionEngine;
use App\Platform\Audit\Audit;
use App\Platform\Context\ExecutionContext;
use App\Platform\Organizations\Organization;
use App\Platform\Products\ProductAccount;
use App\Platform\Products\ProductSnapshot;
use App\Platform\Products\ProductTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * O produto envia o retrato das suas contas para o Foxy Admin (docs/08, seção 3). Lista completa: contas que
 * não vierem passam a "removida". Chamada sempre da própria integração (chave do sistema).
 */
class ProductSnapshotController
{
    public function store(Request $request, PromotionEngine $promotions): JsonResponse
    {
        $data = $request->validate([
            'metrics' => ['present', 'array'],
            'accounts' => ['present', 'array', 'max:50000'],
            'accounts.*.external_ref' => ['required', 'string', 'max:120', 'distinct'],
            'accounts.*.name' => ['required', 'string', 'max:255'],
            'accounts.*.plan' => ['nullable', 'string', 'max:120'],
            'accounts.*.status' => ['required', Rule::in(array_keys(ProductAccount::STATUSES))],
            'accounts.*.mrr' => ['nullable', 'numeric', 'min:0'],
            'accounts.*.signed_up_at' => ['nullable', 'date'],
            'accounts.*.trial_ends_at' => ['nullable', 'date'],
            'accounts.*.last_activity_at' => ['nullable', 'date'],
            // Contato do dono da conta, para os comunicados (o descadastro fica só no Core).
            'accounts.*.contact_name' => ['nullable', 'string', 'max:255'],
            'accounts.*.email' => ['nullable', 'string', 'max:255'],
            'accounts.*.whatsapp' => ['nullable', 'string', 'max:30'],
            // Para o Foxy Admin cuidar da conta (docs/08, seção 3.1).
            'accounts.*.health' => ['nullable', 'string', 'max:20'],
            'accounts.*.problems' => ['nullable', 'array', 'max:20'],
            'accounts.*.problems.*.key' => ['required', 'string', 'max:40'],
            'accounts.*.problems.*.label' => ['required', 'string', 'max:120'],
            'accounts.*.problems.*.detail' => ['nullable', 'string', 'max:1000'],
            'accounts.*.problems.*.help' => ['nullable', 'string', 'max:2000'],
            'accounts.*.usage' => ['nullable', 'array', 'max:30'],
            'accounts.*.fox_credits' => ['nullable', 'integer', 'min:0'],
            'accounts.*.admin_url' => ['nullable', 'url', 'max:255'],
            // Chamados de suporte (abertos, respondidos e fechados há pouco).
            'tickets' => ['nullable', 'array', 'max:5000'],
            'tickets.*.id' => ['required', 'string', 'max:60'],
            'tickets.*.account_ref' => ['nullable', 'string', 'max:120'],
            'tickets.*.subject' => ['nullable', 'string', 'max:255'],
            'tickets.*.category' => ['nullable', 'string', 'max:60'],
            'tickets.*.priority' => ['nullable', 'string', 'max:20'],
            'tickets.*.status' => ['required', Rule::in(array_keys(ProductTicket::STATUSES))],
            'tickets.*.message' => ['required', 'string', 'max:20000'],
            'tickets.*.admin_response' => ['nullable', 'string', 'max:40000'],
            'tickets.*.opened_at' => ['nullable', 'date'],
            'tickets.*.responded_at' => ['nullable', 'date'],
        ]);

        $integrationId = ExecutionContext::current()->integration->getKey();
        $now = now();
        $refs = array_column($data['accounts'], 'external_ref');

        DB::transaction(function () use ($data, $integrationId, $now, $refs) {
            foreach ($data['accounts'] as $account) {
                ProductAccount::updateOrCreate(
                    ['integration_id' => $integrationId, 'external_ref' => $account['external_ref']],
                    [
                        'name' => $account['name'],
                        'plan' => $account['plan'] ?? null,
                        'status' => $account['status'],
                        'mrr' => $account['mrr'] ?? 0,
                        'signed_up_at' => $account['signed_up_at'] ?? null,
                        'trial_ends_at' => $account['trial_ends_at'] ?? null,
                        'last_activity_at' => $account['last_activity_at'] ?? null,
                        'contact_name' => $account['contact_name'] ?? null,
                        'email' => filter_var($account['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: null,
                        'whatsapp' => $account['whatsapp'] ?? null,
                        'health' => $account['health'] ?? null,
                        'problems' => $account['problems'] ?? null,
                        'usage' => $account['usage'] ?? null,
                        'fox_credits' => $account['fox_credits'] ?? null,
                        'admin_url' => $account['admin_url'] ?? null,
                        'seen_at' => $now,
                    ],
                );
            }

            // Lista completa: o que não veio saiu do produto.
            ProductAccount::where('integration_id', $integrationId)
                ->whereNotIn('external_ref', $refs)
                ->where('status', '!=', 'removed')
                ->update(['status' => 'removed', 'mrr' => 0]);

            $accountIds = ProductAccount::where('integration_id', $integrationId)->pluck('id', 'external_ref');
            foreach ($data['tickets'] ?? [] as $ticket) {
                ProductTicket::updateOrCreate(
                    ['integration_id' => $integrationId, 'external_id' => $ticket['id']],
                    [
                        'product_account_id' => $accountIds[$ticket['account_ref'] ?? ''] ?? null,
                        'subject' => $ticket['subject'] ?? null,
                        'category' => $ticket['category'] ?? null,
                        'priority' => $ticket['priority'] ?? null,
                        'status' => $ticket['status'],
                        'message' => $ticket['message'],
                        'admin_response' => $ticket['admin_response'] ?? null,
                        'opened_at' => $ticket['opened_at'] ?? null,
                        'responded_at' => $ticket['responded_at'] ?? null,
                    ],
                );
            }

            ProductSnapshot::create(['integration_id' => $integrationId, 'metrics' => $data['metrics'], 'accounts_count' => count($refs), 'taken_at' => $now]);
        });

        Audit::record('platform.product_snapshot', 'ok', null, ['accounts' => count($refs)]);

        // Conta nova pode entrar numa promoção de IA na hora (ex.: "1º mês ganha +100").
        $granted = $promotions->apply();

        // Perguntas extras de cada conta no mês (promoções, presentes, pacotes): o produto mostra a raposa para quem
        // ganhou, mesmo sem perguntas no plano. Só contas com extra; quem não aparece não tem.
        $organizations = Organization::where('integration_id', $integrationId)->whereIn('external_ref', $refs)->pluck('external_ref', 'id');
        $aiExtra = AiCreditGrant::withoutGlobalScopes()
            ->whereIn('organization_id', $organizations->keys())
            ->where('period', now()->format('Y-m'))
            ->selectRaw('organization_id, sum(credits) as credits')->groupBy('organization_id')
            ->pluck('credits', 'organization_id')
            ->mapWithKeys(fn ($credits, $orgId) => [$organizations[$orgId] => (int) $credits])
            ->filter(fn (int $credits) => $credits > 0);

        return response()->json(['accounts' => count($refs), 'promotion_grants' => $granted, 'ai_extra' => ['period' => now()->format('Y-m'), 'credits' => (object) $aiExtra->all()]]);
    }
}
