<?php

namespace App\Platform\Products;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Fechamento do dia de um produto: contas, em uso, em teste, pagantes e MRR (métricas do Foxy Admin, docs/08
 * seção 3.5). Gravado de hora em hora (`foxy:metricas`); a última gravação do dia é a que fica.
 */
class ProductMetricDay extends Model
{
    use HasUlids;

    protected $fillable = ['integration_id', 'day', 'accounts', 'live', 'trial', 'paying', 'mrr'];

    protected function casts(): array
    {
        // "day" fica texto (Y-m-d): com cast de data o SQLite guardaria a hora junto e o dia não se acharia.
        return ['mrr' => 'decimal:2'];
    }

    /** Grava (ou atualiza) o dia de hoje de cada produto com contas. */
    public static function recordToday(): int
    {
        $today = now()->toDateString();
        $rows = ProductAccount::where('status', '!=', 'removed')->get(['integration_id', 'status', 'mrr'])->groupBy('integration_id');

        foreach ($rows as $integrationId => $accounts) {
            self::updateOrCreate(['integration_id' => $integrationId, 'day' => $today], [
                'accounts' => $accounts->count(),
                'live' => $accounts->whereIn('status', ProductAccount::LIVE)->count(),
                'trial' => $accounts->where('status', 'trial')->count(),
                'paying' => $accounts->filter(fn ($a) => (float) $a->mrr > 0)->count(),
                'mrr' => round((float) $accounts->sum('mrr'), 2),
            ]);
        }

        return $rows->count();
    }
}
