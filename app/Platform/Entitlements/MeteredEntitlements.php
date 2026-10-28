<?php

namespace App\Platform\Entitlements;

use App\Platform\Context\ExecutionContext;
use Illuminate\Support\Facades\DB;

/**
 * Medidor mensal em usage_counters. Limites: "{meter}.monthly" (organização) e
 * "{meter}.monthly_per_subject" (cada sujeito), por organização ou em config/foxy.php.
 */
class MeteredEntitlements implements Entitlements
{
    public function hasFeature(string $feature): bool
    {
        return true;
    }

    public function canUse(string $meter, ?string $subjectRef = null): bool
    {
        $organization = ExecutionContext::current()->organization;

        $orgLimit = $organization->limit("{$meter}.monthly");
        if ($orgLimit !== null && $this->usage($meter)['quantity'] >= $orgLimit) {
            return false;
        }

        if ($subjectRef !== null) {
            $subjectLimit = $organization->limit("{$meter}.monthly_per_subject");
            if ($subjectLimit !== null && $this->usage($meter, $subjectRef)['quantity'] >= $subjectLimit) {
                return false;
            }
        }

        return true;
    }

    public function recordUsage(string $meter, ?string $subjectRef = null, int $quantity = 1, float $costUsd = 0): void
    {
        $this->increment($meter, '', $quantity, $costUsd);

        if ($subjectRef !== null) {
            $this->increment($meter, $subjectRef, $quantity, $costUsd);
        }
    }

    public function usage(string $meter, ?string $subjectRef = null, ?string $period = null): array
    {
        $row = DB::table('usage_counters')
            ->where('organization_id', ExecutionContext::current()->organization->getKey())
            ->where('meter', $meter)
            ->where('subject_ref', $subjectRef ?? '')
            ->where('period', $period ?? $this->period())
            ->first();

        return [
            'quantity' => (int) ($row->quantity ?? 0),
            'cost_usd' => (float) ($row->cost_usd ?? 0),
        ];
    }

    private function increment(string $meter, string $subjectRef, int $quantity, float $costUsd): void
    {
        $key = [
            'organization_id' => ExecutionContext::current()->organization->getKey(),
            'meter' => $meter,
            'subject_ref' => $subjectRef,
            'period' => $this->period(),
        ];

        DB::table('usage_counters')->insertOrIgnore($key + ['created_at' => now(), 'updated_at' => now()]);

        // Incremento no próprio banco: seguro com várias requisições/máquinas ao mesmo tempo.
        DB::table('usage_counters')->where($key)->update([
            'quantity' => DB::raw('quantity + '.(int) $quantity),
            'cost_usd' => DB::raw('cost_usd + '.sprintf('%.6F', $costUsd)),
            'updated_at' => now(),
        ]);
    }

    private function period(): string
    {
        return now()->format('Y-m');
    }
}
