<?php

namespace App\Finance\Transactions;

use App\Finance\Categories\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Filtros da lista de lançamentos: uma regra só para a tela, os totais e a exportação.
 * Período: mês (padrão o atual) ou de/até; conta (inclui transferências que chegam nela); categoria (a geral
 * inclui as de dentro); tipo; situação; busca na descrição.
 */
class TransactionFilters
{
    public function __construct(public readonly array $values, public readonly Carbon $from, public readonly Carbon $to) {}

    public static function fromRequest(Request $request): self
    {
        $values = $request->validate([
            'mes' => ['nullable', 'date_format:Y-m'],
            'de' => ['nullable', 'date'],
            'ate' => ['nullable', 'date'],
            'conta' => ['nullable', 'string', 'max:30'],
            'categoria' => ['nullable', 'string', 'max:30'],
            'tipo' => ['nullable', 'in:gasto,recebimento,transferencia'],
            'situacao' => ['nullable', 'in:efetivado,previsto'],
            'busca' => ['nullable', 'string', 'max:80'],
        ]);

        if (! empty($values['de']) || ! empty($values['ate'])) {
            $from = Carbon::parse($values['de'] ?? '2000-01-01')->startOfDay();
            $to = Carbon::parse($values['ate'] ?? '2100-12-31')->startOfDay();
            unset($values['mes']);
        } else {
            $month = Carbon::createFromFormat('Y-m-d', ($values['mes'] ?? today()->format('Y-m')).'-01')->startOfDay();
            $values['mes'] = $month->format('Y-m');
            [$from, $to] = [$month, $month->copy()->endOfMonth()->startOfDay()];
        }

        return new self(array_filter($values, fn ($v) => $v !== null && $v !== ''), $from, $to);
    }

    public function apply(Builder $query): Builder
    {
        $v = $this->values;

        return $query
            ->whereBetween('date', [$this->from->toDateString(), $this->to->toDateString()])
            ->when($v['conta'] ?? null, fn ($q, $id) => $q->where(fn ($q) => $q->where('account_id', $id)->orWhere('to_account_id', $id)))
            ->when($v['categoria'] ?? null, fn ($q, $id) => $q->whereIn('category_id', Category::where('id', $id)->orWhere('parent_id', $id)->pluck('id')))
            ->when($v['tipo'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->when($v['situacao'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($v['busca'] ?? null, fn ($q, $text) => $q->where('description', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $text).'%'));
    }

    /** Mês anterior e seguinte (para as setas), só quando o filtro é por mês. */
    public function neighbours(): ?array
    {
        if (! isset($this->values['mes'])) {
            return null;
        }

        return [$this->from->copy()->subMonthNoOverflow()->format('Y-m'), $this->from->copy()->addMonthNoOverflow()->format('Y-m')];
    }

    /** Para o cabeçalho do PDF. */
    public function labels(array $accounts, array $categories): array
    {
        $v = $this->values;

        return [
            'Período' => isset($v['mes']) ? self::monthName($this->from) : $this->from->format('d/m/Y').' a '.$this->to->format('d/m/Y'),
            'Conta' => isset($v['conta']) ? ($accounts[$v['conta']] ?? null) : null,
            'Categoria' => isset($v['categoria']) ? ($categories[$v['categoria']] ?? null) : null,
            'Tipo' => isset($v['tipo']) ? Transaction::KINDS[$v['tipo']] : null,
            'Situação' => isset($v['situacao']) ? Transaction::STATUSES[$v['situacao']] : null,
            'Busca' => $v['busca'] ?? null,
        ];
    }

    public static function monthName(Carbon $month): string
    {
        $names = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

        return $names[$month->month - 1].' de '.$month->year;
    }
}
