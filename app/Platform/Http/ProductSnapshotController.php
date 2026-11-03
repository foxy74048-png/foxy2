<?php

namespace App\Platform\Http;

use App\Ai\Promotions\PromotionEngine;
use App\Platform\Audit\Audit;
use App\Platform\Context\ExecutionContext;
use App\Platform\Products\ProductAccount;
use App\Platform\Products\ProductSnapshot;
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
                        'seen_at' => $now,
                    ],
                );
            }

            // Lista completa: o que não veio saiu do produto.
            ProductAccount::where('integration_id', $integrationId)
                ->whereNotIn('external_ref', $refs)
                ->where('status', '!=', 'removed')
                ->update(['status' => 'removed', 'mrr' => 0]);

            ProductSnapshot::create(['integration_id' => $integrationId, 'metrics' => $data['metrics'], 'accounts_count' => count($refs), 'taken_at' => $now]);
        });

        Audit::record('platform.product_snapshot', 'ok', null, ['accounts' => count($refs)]);

        // Conta nova pode entrar numa promoção de IA na hora (ex.: "1º mês ganha +100").
        $granted = $promotions->apply();

        return response()->json(['accounts' => count($refs), 'promotion_grants' => $granted]);
    }
}
