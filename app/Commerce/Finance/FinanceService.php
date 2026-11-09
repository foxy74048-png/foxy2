<?php

namespace App\Commerce\Finance;

use App\Commerce\Orders\Order;
use App\Platform\Audit\Audit;
use App\Platform\Context\ExecutionContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Única porta do financeiro da loja: parcelas a receber (crediário/fiado e avulsas), contas a pagar com
 * repetição e as baixas. Tudo manual: a Foxy não movimenta dinheiro, só registra e lembra.
 */
class FinanceService
{
    public const MAX_INSTALLMENTS = 24;

    /**
     * Divide um valor em parcelas mensais (o resto dos centavos vai na primeira).
     *
     * @return Receivable[]
     */
    public function installments(array $data, int $totalCents, int $count, Carbon $firstDue, ?Order $order = null): array
    {
        if ($totalCents <= 0) {
            throw ValidationException::withMessages(['amount' => 'Informe um valor maior que zero.']);
        }
        $count = max(1, min(self::MAX_INSTALLMENTS, $count));
        $base = intdiv($totalCents, $count);
        $first = $base + ($totalCents - $base * $count);

        return DB::transaction(function () use ($data, $count, $base, $first, $firstDue, $order) {
            $created = [];
            for ($i = 1; $i <= $count; $i++) {
                $created[] = Receivable::create([
                    'customer_id' => $data['customer_id'] ?? $order?->customer_id,
                    'order_id' => $order?->id,
                    'description' => Str::limit($data['description'] ?? ($order ? "Pedido #{$order->number}" : 'Conta a receber'), 160, ''),
                    'amount_cents' => $i === 1 ? $first : $base,
                    'due_date' => $firstDue->copy()->addMonthsNoOverflow($i - 1)->toDateString(),
                    'installment' => $count > 1 ? $i : null,
                    'installments' => $count > 1 ? $count : null,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $this->userId(),
                ]);
            }
            Audit::record('commerce.receivable.created', metadata: ['count' => $count, 'order' => $order?->id]);

            return $created;
        });
    }

    /**
     * Baixa (total ou parcial). Recebeu menos? O que falta vira uma conta nova com o mesmo vencimento.
     */
    public function receive(Receivable $receivable, ?int $cents = null, ?string $method = null, ?Carbon $at = null): ?Receivable
    {
        $this->guardOpen($receivable->isOpen());
        $cents = $cents ?? $receivable->amount_cents;
        if ($cents <= 0 || $cents > $receivable->amount_cents) {
            throw ValidationException::withMessages(['amount' => 'O valor recebido deve ser maior que zero e até '.number_format($receivable->amount_cents / 100, 2, ',', '.').'.']);
        }

        return DB::transaction(function () use ($receivable, $cents, $method, $at) {
            $rest = null;
            if ($cents < $receivable->amount_cents) {
                $rest = $receivable->replicate(['reminders', 'received_at', 'received_cents']);
                $rest->fill(['amount_cents' => $receivable->amount_cents - $cents, 'description' => Str::limit('Saldo: '.$receivable->description, 160, ''), 'status' => 'aberto']);
                $rest->save();
                $receivable->amount_cents = $cents;
            }
            $receivable->fill(['status' => 'recebido', 'received_at' => $at ?? now(), 'received_cents' => $cents, 'payment_method' => $method])->save();
            Audit::record('commerce.receivable.received', subject: $receivable, metadata: ['cents' => $cents, 'rest' => $rest?->amount_cents]);

            return $rest;
        });
    }

    /** Desfaz uma baixa (lançou errado). */
    public function reopenReceivable(Receivable $receivable): void
    {
        if ($receivable->status !== 'recebido') {
            throw ValidationException::withMessages(['status' => 'Só dá para reabrir uma conta recebida.']);
        }
        $receivable->update(['status' => 'aberto', 'received_at' => null, 'received_cents' => null, 'payment_method' => null]);
        Audit::record('commerce.receivable.reopened', subject: $receivable);
    }

    public function cancelReceivable(Receivable $receivable): void
    {
        $this->guardOpen($receivable->isOpen());
        $receivable->update(['status' => 'cancelado']);
        Audit::record('commerce.receivable.canceled', subject: $receivable);
    }

    /** Pedido cancelado: as parcelas em aberto dele também. */
    public function cancelOrderReceivables(Order $order): int
    {
        return Receivable::where('order_id', $order->id)->where('status', 'aberto')->update(['status' => 'cancelado', 'updated_at' => now()]);
    }

    /**
     * Conta a pagar: única, parcelada (divide o valor) ou repetida todo mês (mesmo valor).
     *
     * @return Payable[]
     */
    public function payables(array $data, string $mode = 'unica', int $count = 1): array
    {
        $count = $mode === 'unica' ? 1 : max(2, min($mode === 'mensal' ? 36 : self::MAX_INSTALLMENTS, $count));
        $total = (int) $data['amount_cents'];
        if ($total <= 0) {
            throw ValidationException::withMessages(['amount' => 'Informe um valor maior que zero.']);
        }
        $each = $mode === 'parcelas' ? intdiv($total, $count) : $total;
        $firstExtra = $mode === 'parcelas' ? $total - $each * $count : 0;
        $series = $count > 1 ? strtolower((string) Str::ulid()) : null;
        $firstDue = Carbon::parse($data['due_date']);

        return DB::transaction(function () use ($data, $mode, $count, $each, $firstExtra, $series, $firstDue) {
            $created = [];
            for ($i = 1; $i <= $count; $i++) {
                $created[] = Payable::create([
                    'description' => Str::limit($data['description'], 160, ''),
                    'supplier' => $data['supplier'] ?? null,
                    'category' => $data['category'] ?? 'outros',
                    'amount_cents' => $each + ($i === 1 ? $firstExtra : 0),
                    'due_date' => $firstDue->copy()->addMonthsNoOverflow($i - 1)->toDateString(),
                    'notes' => $data['notes'] ?? null,
                    'series_id' => $series,
                    'installment' => $mode === 'parcelas' ? $i : null,
                    'installments' => $mode === 'parcelas' ? $count : null,
                    'created_by' => $this->userId(),
                ]);
            }
            Audit::record('commerce.payable.created', metadata: ['mode' => $mode, 'count' => $count]);

            return $created;
        });
    }

    public function pay(Payable $payable, ?int $cents = null, ?string $method = null, ?Carbon $at = null): void
    {
        $this->guardOpen($payable->isOpen());
        $payable->update(['status' => 'pago', 'paid_at' => $at ?? now(), 'paid_cents' => $cents ?? $payable->amount_cents, 'payment_method' => $method]);
        Audit::record('commerce.payable.paid', subject: $payable);
    }

    public function reopenPayable(Payable $payable): void
    {
        if ($payable->status !== 'pago') {
            throw ValidationException::withMessages(['status' => 'Só dá para reabrir uma conta paga.']);
        }
        $payable->update(['status' => 'aberto', 'paid_at' => null, 'paid_cents' => null, 'payment_method' => null]);
        Audit::record('commerce.payable.reopened', subject: $payable);
    }

    /** Cancela a conta; com $future, também as próximas em aberto da mesma repetição. */
    public function cancelPayable(Payable $payable, bool $future = false): int
    {
        $this->guardOpen($payable->isOpen());
        $query = $future && $payable->series_id
            ? Payable::where('series_id', $payable->series_id)->where('status', 'aberto')->where('due_date', '>=', $payable->due_date->toDateString())
            : Payable::whereKey($payable->id);
        $count = $query->update(['status' => 'cancelado', 'updated_at' => now()]);
        Audit::record('commerce.payable.canceled', subject: $payable, metadata: ['count' => $count]);

        return $count;
    }

    private function guardOpen(bool $open): void
    {
        if (! $open) {
            throw ValidationException::withMessages(['status' => 'Esta conta não está em aberto.']);
        }
    }

    private function userId(): ?string
    {
        $context = ExecutionContext::current();

        return $context->principalType === ExecutionContext::PRINCIPAL_USER ? (string) $context->principalId : null;
    }
}
