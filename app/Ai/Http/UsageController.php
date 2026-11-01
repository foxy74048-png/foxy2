<?php

namespace App\Ai\Http;

use App\Ai\Plans\AiAccount;
use App\Ai\Usage\UsageReport;
use App\Platform\Context\ExecutionContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsageController
{
    public function show(Request $request, AiAccount $account): JsonResponse
    {
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = $data['month'] ?? now()->format('Y-m');

        return response()->json(
            UsageReport::forOrganization(ExecutionContext::current()->organization->getKey(), $month)
            + ['credits' => $account->summary($month)]
        );
    }
}
