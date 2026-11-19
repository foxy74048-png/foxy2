<?php

namespace App\Finance\Accounts;

use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Conta da carteira: corrente, poupança, dinheiro (a carteira de bolso), vale (VR/VA) ou cartão de crédito.
 * O cartão tem dia de fechamento e de vencimento: cada compra cai numa fatura (invoice_month = mês do vencimento).
 */
class Account extends Model
{
    use BelongsToOrganization, HasUlids;

    public const CARD = 'cartao';

    public const KINDS = [
        'corrente' => 'Conta corrente',
        'poupanca' => 'Poupança',
        'dinheiro' => 'Dinheiro (carteira)',
        'cartao' => 'Cartão de crédito',
        'vale' => 'Vale-refeição / alimentação',
    ];

    protected $table = 'finance_accounts';

    protected $fillable = ['kind', 'name', 'institution', 'opening_balance_cents', 'closing_day', 'due_day', 'limit_cents', 'archived_at', 'statement_mapping'];

    protected function casts(): array
    {
        return [
            'opening_balance_cents' => 'integer', 'closing_day' => 'integer', 'due_day' => 'integer',
            'limit_cents' => 'integer', 'archived_at' => 'datetime', 'statement_mapping' => 'array',
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    public function isCard(): bool
    {
        return $this->kind === self::CARD;
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    /**
     * Fatura em que cai uma compra feita em $date ("2026-12" = fatura que vence em dezembro). Compra no dia do
     * fechamento ou depois vai para a fatura seguinte; se o vencimento é antes do fechamento no mês (ex.: fecha
     * dia 25, vence dia 5), a fatura vence no mês depois do fechamento.
     */
    public function invoiceMonthFor(Carbon|string $date): ?string
    {
        if (! $this->isCard() || ! $this->closing_day) {
            return null;
        }

        $date = Carbon::parse($date)->startOfDay();
        $closing = $date->copy()->startOfMonth();
        if ($date->day >= min($this->closing_day, $date->daysInMonth)) {
            $closing->addMonthNoOverflow();
        }
        $due = $this->due_day && $this->due_day <= $this->closing_day ? $closing->copy()->addMonthNoOverflow() : $closing;

        return $due->format('Y-m');
    }

    /**
     * Que fatura um pagamento feito em $date paga: a última que já fechou (a anterior à que está aberta).
     * Ex.: fecha dia 10 e vence dia 17; pagar em 15/11 paga a de novembro (a de dezembro ainda está aberta).
     */
    public function paidInvoiceFor(Carbon|string $date): ?string
    {
        $open = $this->invoiceMonthFor($date);

        return $open ? self::addMonths($open, -1) : null;
    }

    /** Dia em que a fatura do mês $invoiceMonth (mês do vencimento) fecha. */
    public function closingDate(string $invoiceMonth): Carbon
    {
        $due = Carbon::createFromFormat('Y-m-d', $invoiceMonth.'-01')->startOfDay();
        $month = $this->due_day && $this->due_day <= $this->closing_day ? $due->copy()->subMonthNoOverflow() : $due;

        return $month->copy()->day(min($this->closing_day, $month->daysInMonth));
    }

    /** Dia em que a fatura do mês $invoiceMonth vence. */
    public function dueDate(string $invoiceMonth): Carbon
    {
        $month = Carbon::createFromFormat('Y-m-d', $invoiceMonth.'-01')->startOfDay();

        return $month->copy()->day(min($this->due_day ?? 1, $month->daysInMonth));
    }

    /** Fatura N meses depois de outra ("2026-12" + 2 = "2027-02"). */
    public static function addMonths(string $invoiceMonth, int $months): string
    {
        return Carbon::createFromFormat('Y-m-d', $invoiceMonth.'-01')->addMonthsNoOverflow($months)->format('Y-m');
    }
}
