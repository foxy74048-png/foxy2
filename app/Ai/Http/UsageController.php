<?php

namespace App\Ai\Http;

use App\Ai\Assistant\Assistant;
use App\Ai\Plans\AiAccount;
use App\Ai\Usage\UsageReport;
use App\Platform\Context\ExecutionContext;
use App\Platform\Entitlements\Entitlements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsageController
{
    /**
     * Uso do mês do cliente: perguntas, custo por modelo, créditos (do plano, extras, usados) e por modo.
     * Com user_ref, também o que aquela pessoa usou (para a tela de configurações da janela da raposa).
     */
    public function show(Request $request, AiAccount $account, Entitlements $entitlements): JsonResponse
    {
        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'user_ref' => ['nullable', 'string', 'max:120'],
        ]);
        $month = $data['month'] ?? now()->format('Y-m');
        $organizationId = ExecutionContext::current()->organization->getKey();

        $response = UsageReport::forOrganization($organizationId, $month)
            + ['credits' => $account->summary($month)]
            + ['credits_by_tier' => UsageReport::forOrganizations([$organizationId], $month)[$organizationId]['credits_by_tier']];

        if (! empty($data['user_ref'])) {
            $response['user'] = [
                'user_ref' => $data['user_ref'],
                'messages' => $entitlements->usage(Assistant::METER, $data['user_ref'], $month)['quantity'],
                'credits' => round($entitlements->usage(AiAccount::CREDITS_METER, $data['user_ref'], $month)['quantity'] / 1000, 1),
            ];
        }

        return response()->json($response);
    }
}
