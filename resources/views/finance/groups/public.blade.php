<x-core::plain :title="$group->name" product="Foxy Finanças">
    @php($money = fn ($c) => \App\Platform\Support\Money::format($c))
    {{-- Página pública, só de leitura (sem login). Mostra só nomes e valores do grupo; nada da carteira de quem criou. --}}
    <div>
        <h2 style="margin:0 0 4px">{{ $group->name }}</h2>
        <p class="muted" style="margin:0">Total do grupo: <strong>{{ $money($balances->sum('share')) }}</strong>@if ($group->isClosed()) · encerrado @endif</p>
    </div>

    <div>
        <h3 style="margin:8px 0;">Quem paga quem</h3>
        @forelse ($settlements as $s)
            <div style="padding:6px 0;border-bottom:1px solid var(--border);"><strong>{{ $s['from']->is_me ? 'Quem criou o grupo' : $s['from']->name }}</strong> paga <strong>{{ $s['to']->is_me ? 'quem criou o grupo' : $s['to']->name }}</strong>: <strong>{{ $money($s['amount']) }}</strong></div>
        @empty
            <div class="muted">Tudo acertado.</div>
        @endforelse
    </div>

    <div>
        <h3 style="margin:8px 0;">Quanto cada um gastou e pagou</h3>
        <div class="table-wrap"><table>
            <thead><tr><th>Pessoa</th><th class="num">Gastou</th><th class="num">Pagou</th><th class="num">Recebe / deve</th></tr></thead>
            <tbody>
                @foreach ($balances as $b)
                    <tr>
                        <td>{{ $b['member']->is_me ? 'Quem criou o grupo' : $b['member']->name }}</td>
                        <td class="num">{{ $money($b['share']) }}</td>
                        <td class="num">{{ $money($b['paid']) }}</td>
                        <td class="num">{{ $b['net'] > 0 ? 'recebe '.$money($b['net']) : ($b['net'] < 0 ? 'deve '.$money(-$b['net']) : 'quite') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>
    </div>

    <div>
        <h3 style="margin:8px 0;">Despesas</h3>
        @foreach ($expenses as $e)
            <div style="display:flex;justify-content:space-between;gap:8px;padding:4px 0;border-bottom:1px solid var(--border);"><span>{{ $e->date->format('d/m') }} · {{ $e->description }} <span class="muted">({{ $e->payer?->is_me ? 'quem criou o grupo' : $e->payer?->name }} pagou)</span></span><span>{{ $money($e->amount_cents) }}</span></div>
        @endforeach
    </div>
    <p class="helper" style="margin:0;">Feito no Foxy Finanças.</p>
</x-core::plain>
