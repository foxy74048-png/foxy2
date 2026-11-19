<x-core::layout title="Metas de guardar" product="Foxy Finanças" :subtitle="$currentWallet->name">
    <x-slot:nav>@include('finance.nav')</x-slot:nav>
    <x-slot:actions>@include('platform.export-buttons')@if ($canManage)@include('finance._add', ['label' => 'Nova meta', 'target' => 'nova-meta'])@endif</x-slot:actions>
    @php($money = fn ($c) => \App\Platform\Support\Money::format($c))
    @php($input = fn ($c) => \App\Platform\Support\Money::input($c))

    @include('finance.status')

    {{-- Atalho: reserva de emergência com 6x o gasto médio --}}
    @if ($canManage && ! $hasReserve && $reserveTarget)
        <form class="card" method="POST" action="{{ route('finance.goals.store') }}" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:16px;border-color:var(--border-strong);">
            @csrf
            <input type="hidden" name="name" value="Reserva de emergência">
            <input type="hidden" name="target" value="{{ $input($reserveTarget) }}">
            @if ($savingsAccounts->count() === 1)<input type="hidden" name="account_id" value="{{ $savingsAccounts->first()->id }}">@endif
            <div style="flex:1;min-width:240px;">
                <strong>Comece pela reserva de emergência</strong>
                <div class="helper">O recomendado é guardar 6 meses do que você gasta. Você gasta em média {{ $money($avgExpense) }} por mês: a meta seria <strong>{{ $money($reserveTarget) }}</strong>.</div>
            </div>
            <button type="submit">Criar essa meta</button>
        </form>
    @endif

    @if ($canManage)
        <form class="card form-grid" id="nova-meta" method="POST" action="{{ route('finance.goals.store') }}" style="align-items:end;">
            @csrf
            <div style="grid-column:1/-1;"><h3 style="margin:0;">Nova meta</h3><p class="helper" style="margin:4px 0 0;">Viagem, trocar o carro, um curso, a entrada do apartamento. Com prazo, mostramos quanto guardar por mês para chegar lá.</p></div>
            <div><label for="g-name">O que é</label><input id="g-name" name="name" value="{{ old('name') }}" required maxlength="80" placeholder="Ex.: Viagem de julho"></div>
            <div><label for="g-target">Quanto quer juntar (R$)</label><input id="g-target" name="target" value="{{ old('target') }}" inputmode="decimal" required></div>
            <div><label for="g-deadline">Até quando (opcional)</label><input id="g-deadline" name="deadline" type="date" value="{{ old('deadline') }}"></div>
            <div><label for="g-start">Quanto já tem (R$)</label><input id="g-start" name="start" value="{{ old('start') }}" inputmode="decimal" placeholder="0,00"></div>
            <div>
                <label for="g-account">Onde o dinheiro fica (opcional)</label>
                <select id="g-account" name="account_id"><option value="">— só acompanhar aqui —</option>@foreach ($accounts->reject->isCard() as $a)<option value="{{ $a->id }}" @selected(old('account_id') === $a->id)>{{ $a->name }}</option>@endforeach</select>
            </div>
            <div><button type="submit">Criar meta</button></div>
        </form>
    @endif

    @if ($goals->isEmpty())
        <div class="card" style="margin-top:16px;"><div class="empty">Nenhuma meta ainda.</div></div>
    @endif

    @foreach ($goals as $row)
        @php($g = $row['goal'])
        @php($p = $row['progress'])
        <div class="card" style="margin-top:16px;@if ($g->archived_at) opacity:.6; @endif">
            <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:baseline;">
                <h3 style="margin:0;">{{ $g->name }}</h3>
                <span @class(['badge', 'success' => in_array($row['status'], ['Alcançada', 'No ritmo'], true), 'danger' => in_array($row['status'], ['Atrasada', 'Prazo passou'], true)])>{{ $row['status'] }}</span>
            </div>
            <div style="display:flex;justify-content:space-between;gap:8px;margin-top:10px;flex-wrap:wrap;">
                <span><strong style="font-size:1.25rem;">{{ $money($p['saved']) }}</strong> <span class="muted">de {{ $money($g->target_cents) }}</span></span>
                <span class="helper">
                    @if ($p['done']) meta alcançada{{ $g->achieved_at ? ' em '.$g->achieved_at->format('d/m/Y') : '' }}
                    @else falta {{ $money($p['missing']) }}@if ($g->deadline) · até {{ $g->deadline->format('d/m/Y') }} @endif
                    @endif
                </span>
            </div>
            <div style="height:12px;border-radius:6px;background:var(--border);margin-top:6px;" role="progressbar" aria-valuenow="{{ round($p['pct'] * 100) }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ $g->name }}">
                <div style="height:12px;border-radius:6px;background:{{ $p['done'] ? 'var(--success)' : 'var(--primary)' }};width:{{ max(2, round($p['pct'] * 100)) }}%;"></div>
            </div>
            <div class="helper" style="margin-top:6px;">
                {{ round($p['pct'] * 100) }}%
                @if ($p['per_month']) · para chegar no prazo: <strong>{{ $money($p['per_month']) }} por mês</strong> ({{ $p['months_left'] }} {{ $p['months_left'] === 1 ? 'mês' : 'meses' }})@endif
                @if ($p['on_track'] === false) · pelo ritmo, já deveria ter {{ $money($p['expected']) }} @endif
                @if ($g->account) · fica em {{ $g->account->name }} @endif
            </div>

            @if ($canManage && ! $g->archived_at)
                {{-- Guardei / Tirei: sempre à vista --}}
                <form method="POST" action="{{ route('finance.goals.entry', $g) }}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-top:12px;">
                    @csrf
                    <div><label for="e-dir-{{ $g->id }}">O quê</label><select id="e-dir-{{ $g->id }}" name="direction"><option value="guardei">Guardei</option><option value="tirei">Tirei</option></select></div>
                    <div><label for="e-amount-{{ $g->id }}">Valor (R$)</label><input id="e-amount-{{ $g->id }}" name="amount" inputmode="decimal" required style="max-width:120px;" value="{{ $p['per_month'] ? $input($p['per_month']) : '' }}"></div>
                    <div><label for="e-date-{{ $g->id }}">Data</label><input id="e-date-{{ $g->id }}" name="date" type="date" value="{{ today()->toDateString() }}" required></div>
                    @if ($g->account_id)
                        <div>
                            <label for="e-other-{{ $g->id }}">De / para a conta</label>
                            <select id="e-other-{{ $g->id }}" name="other_account_id"><option value="">— só anotar —</option>@foreach ($accounts->reject->isCard()->where('id', '!=', $g->account_id) as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select>
                        </div>
                    @endif
                    <div><label for="e-note-{{ $g->id }}">Observação</label><input id="e-note-{{ $g->id }}" name="note" maxlength="160" style="max-width:180px;"></div>
                    <div><button type="submit">Registrar</button></div>
                </form>
                @if ($g->account_id)<p class="helper" style="margin:4px 0 0;">Escolhendo a conta, registramos também a transferência entre as suas contas (ex.: do Nubank para a {{ $g->account?->name }}). Nada sai do banco por aqui.</p>@endif
            @endif

            @if ($row['entries']->isNotEmpty())
                <div class="table-wrap" style="margin-top:12px;"><table>
                    <tbody>
                        @foreach ($row['entries'] as $e)
                            <tr>
                                <td style="white-space:nowrap;">{{ $e->date->format('d/m/Y') }}</td>
                                <td>{{ $e->amount_cents >= 0 ? 'Guardei' : 'Tirei' }}@if ($e->note) <span class="muted">· {{ $e->note }}</span>@endif @if ($e->transaction)<div class="helper">{{ $e->transaction->account?->name }} → {{ $e->transaction->toAccount?->name }}</div>@endif</td>
                                <td class="num" style="{{ $e->amount_cents >= 0 ? 'color:var(--success-text);' : '' }}">{{ $e->amount_cents >= 0 ? '+ ' : '− ' }}{{ $money(abs($e->amount_cents)) }}</td>
                                @if ($canManage)<td style="text-align:right;"><form method="POST" action="{{ route('finance.goals.entry.destroy', $e) }}" onsubmit="return confirm('Apagar esta entrada?{{ $e->transaction_id ? ' A transferência ligada também sai dos lançamentos.' : '' }}');">@csrf<button type="submit" class="secondary">Apagar</button></form></td>@endif
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
            @endif

            @if ($canManage)
                <details style="margin-top:10px;">
                    <summary class="button secondary" style="list-style:none;cursor:pointer;display:inline-block;">Editar meta</summary>
                    <form method="POST" action="{{ route('finance.goals.update', $g) }}" class="form-grid" style="margin-top:10px;align-items:end;">
                        @csrf
                        <div><label>Nome<input name="name" value="{{ $g->name }}" maxlength="80" required></label></div>
                        <div><label>Quanto quer juntar<input name="target" value="{{ $input($g->target_cents) }}" inputmode="decimal" required></label></div>
                        <div><label>Prazo<input name="deadline" type="date" value="{{ $g->deadline?->toDateString() }}"></label></div>
                        <div><label>Quanto já tinha<input name="start" value="{{ $input($g->start_cents) }}" inputmode="decimal"></label></div>
                        <div><label>Onde fica<select name="account_id"><option value="">— só acompanhar aqui —</option>@foreach ($accounts->reject->isCard() as $a)<option value="{{ $a->id }}" @selected($g->account_id === $a->id)>{{ $a->name }}</option>@endforeach</select></label></div>
                        <div><button type="submit">Salvar</button></div>
                    </form>
                    <div style="display:flex;gap:8px;margin-top:8px;">
                        <form method="POST" action="{{ route('finance.goals.archive', $g) }}">@csrf<input type="hidden" name="archive" value="{{ $g->archived_at ? 0 : 1 }}"><button type="submit" class="secondary">{{ $g->archived_at ? 'Voltar para as metas' : 'Guardar no histórico' }}</button></form>
                        <form method="POST" action="{{ route('finance.goals.destroy', $g) }}" onsubmit="return confirm('Apagar a meta e as entradas dela?');">@csrf<button type="submit" class="danger">Apagar</button></form>
                    </div>
                </details>
            @endif
        </div>
    @endforeach
</x-core::layout>
