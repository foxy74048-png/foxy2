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
    /**
     * Resumo de várias organizações de uma vez: perguntas, créditos por modo (faixa) e custo.
     *
     * @param  list<string>  $organizationIds
     * @return \Illuminate\Support\Collection<string, array{messages: int, credits: float, credits_by_tier: array<string, float>, cost_usd: float}>
     */
    public static function forOrganizations(array $organizationIds, string $month): \Illuminate\Support\Collection
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $month.'-01 00:00:00');
        $creditUsd = (float) config('foxy.ai.credit_usd');

        $costs = DB::table('usage_records')
            ->whereIn('organization_id', $organizationIds)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $start->addMonth())
            ->groupBy('organization_id', 'model')
            ->selectRaw('organization_id, model, sum(cost_usd) as cost_usd')
            ->get()
            ->groupBy('organization_id');

        $messages = DB::table('usage_counters')
            ->whereIn('organization_id', $organizationIds)
            ->where('meter', Assistant::METER)
            ->where('subject_ref', '')
            ->where('period', $month)
            ->pluck('quantity', 'organization_id');

        return collect($organizationIds)->mapWithKeys(function (string $id) use ($costs, $messages, $creditUsd) {
            $rows = $costs->get($id, collect());
            $byTier = [];
            foreach ($rows as $row) {
                $tier = \App\Ai\Plans\AiPlan::tierOfModel($row->model) ?? 'outro';
                $byTier[$tier] = round(($byTier[$tier] ?? 0) + (float) $row->cost_usd / $creditUsd, 1);
            }
            $cost = round((float) $rows->sum('cost_usd'), 6);

            return [$id => [
                'messages' => (int) ($messages[$id] ?? 0),
                'credits' => round($cost / $creditUsd, 1),
                'credits_by_tier' => $byTier,
                'cost_usd' => $cost,
            ]];
        });
    }

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
