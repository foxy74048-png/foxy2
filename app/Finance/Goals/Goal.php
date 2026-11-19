<?php

namespace App\Finance\Goals;

use App\Finance\Accounts\Account;
use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Meta de guardar dinheiro (reserva de emergência, viagem, trocar o carro). Juntado = o que já tinha no começo
 * + as entradas ("guardei" positivo, "tirei" negativo).
 */
class Goal extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $table = 'finance_goals';

    protected $fillable = ['name', 'target_cents', 'deadline', 'account_id', 'start_cents', 'achieved_at', 'archived_at'];

    protected function casts(): array
    {
        return ['target_cents' => 'integer', 'start_cents' => 'integer', 'achieved_at' => 'datetime', 'archived_at' => 'datetime'];
    }

    /** Só "Y-m-d" no banco (igual ao lançamento). */
    protected function deadline(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? null : Carbon::parse($value)->startOfDay(),
            set: fn ($value) => $value === null || $value === '' ? null : Carbon::parse($value)->toDateString(),
        );
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(GoalEntry::class);
    }

    public function savedCents(): int
    {
        return $this->start_cents + (int) ($this->entries_sum_amount_cents ?? $this->entries()->sum('amount_cents'));
    }

    /**
     * Onde a meta está: juntado, falta, % e — com prazo — quanto guardar por mês para chegar e se está no ritmo
     * (comparando com uma linha reta do dia em que a meta foi criada até o prazo).
     *
     * @return array{saved: int, missing: int, pct: float, months_left: ?int, per_month: ?int, on_track: ?bool, expected: ?int, done: bool}
     */
    public function progress(): array
    {
        $saved = $this->savedCents();
        $missing = max(0, $this->target_cents - $saved);
        $today = today();
        $monthsLeft = null;
        $perMonth = null;
        $onTrack = null;
        $expected = null;

        if ($this->deadline && $missing > 0) {
            // Meses que faltam, contando o atual (prazo no mês que vem = 2 meses para guardar: este e o próximo).
            $monthsLeft = max(1, ($this->deadline->year - $today->year) * 12 + $this->deadline->month - $today->month + 1);
            $perMonth = (int) ceil($missing / $monthsLeft);
            $start = $this->created_at?->copy()->startOfDay() ?? $today;
            $total = max(1, $start->diffInDays($this->deadline));
            $elapsed = min($total, max(0, $start->diffInDays($today)));
            $expected = (int) round($this->target_cents * $elapsed / $total);
            $onTrack = $saved >= $expected;
        }

        return [
            'saved' => $saved,
            'missing' => $missing,
            'pct' => $this->target_cents > 0 ? min(1, $saved / $this->target_cents) : 0,
            'months_left' => $monthsLeft,
            'per_month' => $perMonth,
            'on_track' => $onTrack,
            'expected' => $expected,
            'done' => $missing === 0,
            'late' => $this->deadline !== null && $this->deadline->lt($today) && $missing > 0,
        ];
    }
}
