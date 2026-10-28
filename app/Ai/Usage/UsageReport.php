<?php

namespace App\Ai\Usage;

use App\Ai\Assistant\Assistant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Uso do mês de uma organização: mensagens (do medidor) e chamadas/tokens/custo por modelo (de usage_records).
 */
class UsageReport
{
    public static function forOrganization(string $organizationId, string $month): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $month.'-01 00:00:00');

        $byModel = DB::table('usage_records')
            ->where('organization_id', $organizationId)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $start->addMonth())
            ->groupBy('model')
            ->orderBy('model')
            ->selectRaw('model, count(*) as calls, sum(input_tokens) as input_tokens, sum(output_tokens) as output_tokens,
                sum(cache_read_tokens) as cache_read_tokens, sum(cache_write_tokens) as cache_write_tokens, sum(cost_usd) as cost_usd')
            ->get()
            ->map(fn ($row) => [
                'model' => $row->model,
                'calls' => (int) $row->calls,
                'input_tokens' => (int) $row->input_tokens,
                'output_tokens' => (int) $row->output_tokens,
                'cache_read_tokens' => (int) $row->cache_read_tokens,
                'cache_write_tokens' => (int) $row->cache_write_tokens,
                'cost_usd' => round((float) $row->cost_usd, 6),
            ])
            ->all();

        $messages = (int) DB::table('usage_counters')
            ->where('organization_id', $organizationId)
            ->where('meter', Assistant::METER)
            ->where('subject_ref', '')
            ->where('period', $month)
            ->value('quantity');

        return [
            'month' => $month,
            'messages' => $messages,
            'cost_usd' => round(array_sum(array_column($byModel, 'cost_usd')), 6),
            'by_model' => $byModel,
        ];
    }
}
