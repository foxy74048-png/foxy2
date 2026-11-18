<x-core::layout title="Contas e cartões" product="Foxy Finanças" :subtitle="$currentWallet->name">
    <x-slot:nav>@include('finance.nav')</x-slot:nav>
    <x-slot:actions>@include('platform.export-buttons')@if ($canManage)@include('finance._add', ['label' => 'Nova conta', 'target' => 'nova-conta'])@endif</x-slot:actions>
    @php($money = fn ($c) => \App\Platform\Support\Money::format($c))
    @php($input = fn ($c) => \App\Platform\Support\Money::input($c))

    @include('finance.status')

    <div class="grid cards">
        <div class="card"><div class="muted">Saldo hoje (contas, sem cartões)</div><h2 style="margin:4px 0;{{ $total < 0 ? 'color:var(--danger-text);' : '' }}">{{ $money($total) }}</h2><div class="helper">saldo inicial + o que já foi feito até hoje</div></div>
    </div>

    @if ($canManage)
        <form class="card form-grid" id="nova-conta" method="POST" action="{{ route('finance.accounts.store') }}" style="margin-top:16px;align-items:end;">
            @csrf
            <div style="grid-column:1/-1;"><h3 style="margin:0;">Nova conta ou cartão</h3></div>
            <div><label for="n-kind">Tipo</label><select id="n-kind" name="kind">@foreach ($kinds as $k => $l)<option value="{{ $k }}" @selected(old('kind') === $k)>{{ $l }}</option>@endforeach</select></div>
            <div><label for="n-name">Nome</label><input id="n-name" name="name" value="{{ old('name') }}" required maxlength="80" placeholder="Ex.: Nubank, Carteira"></div>
            <div><label for="n-inst">Banco (opcional)</label><input id="n-inst" name="institution" value="{{ old('institution') }}" maxlength="80"></div>
            <div><label for="n-open">Saldo de hoje (R$)</label><input id="n-open" name="opening_balance" value="{{ old('opening_balance') }}" inputmode="decimal" placeholder="0,00"></div>
            <div><label for="n-close">Cartão: fecha dia</label><input id="n-close" name="closing_day" type="number" min="1" max="31" value="{{ old('closing_day') }}"></div>
            <div><label for="n-due">Cartão: vence dia</label><input id="n-due" name="due_day" type="number" min="1" max="31" value="{{ old('due_day') }}"></div>
            <div><label for="n-limit">Cartão: limite (R$)</label><input id="n-limit" name="limit" value="{{ old('limit') }}" inputmode="decimal"></div>
            <div><button type="submit">Cadastrar</button></div>
            <p class="helper" style="grid-column:1/-1;margin:0;">Cartão não tem saldo: os gastos dele vão para a fatura. Pagar a fatura é uma <b>transferência</b> da conta para o cartão, em Lançamentos.</p>
        </form>
    @endif

    <div class="card" style="margin-top:16px;">
        @if ($accounts->isEmpty())
            <div class="empty">Nenhuma conta ainda. Cadastre onde o seu dinheiro fica: conta do banco, carteira, cartão de crédito, vale.</div>
        @else
            <div class="table-wrap"><table>
                <thead><tr><th>Conta</th><th>Tipo</th><th style="text-align:right;">Saldo hoje / fatura atual</th>@if ($canManage)<th style="text-align:right;">Ações</th>@endif</tr></thead>
                <tbody>
                    @foreach ($accounts as $a)
                        <tr @if ($a->archived_at) style="opacity:.6;" @endif>
                            <td><strong>{{ $a->name }}</strong>@if ($a->archived_at) <span class="badge">arquivada</span>@endif<div class="helper">{{ $a->institution }}</div></td>
                            <td>{{ $a->kindLabel() }}@if ($a->isCard())<div class="helper">fecha dia {{ $a->closing_day }} · vence dia {{ $a->due_day }}</div>@endif</td>
                            <td style="text-align:right;white-space:nowrap;">
                                @if ($a->isCard())
                                    {{ $money($invoiceSpent[$a->id] ?? 0) }}
                                    <div class="helper">fatura de {{ \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $a->invoiceMonthFor(today()).'-01')->format('m/Y') }}@if ($a->limit_cents) · limite {{ $money($a->limit_cents) }}@endif</div>
                                @else
                                    <span @if ($balances[$a->id] < 0) style="color:var(--danger-text);" @endif>{{ $money($balances[$a->id]) }}</span>
                                @endif
                                <div class="helper"><a href="{{ route('finance.transactions', ['conta' => $a->id]) }}">ver lançamentos</a></div>
                            </td>
                            @if ($canManage)
                                <td style="text-align:right;white-space:nowrap;">
                                    <details style="display:inline-block;text-align:left;">
                                        <summary class="button secondary" style="list-style:none;cursor:pointer;">Editar</summary>
                                        <div class="card" style="position:absolute;right:24px;z-index:5;min-width:320px;margin-top:6px;box-shadow:var(--shadow);">
                                            <form method="POST" action="{{ route('finance.accounts.update', $a) }}" class="stack">
                                                @csrf
                                                <label>Nome<input name="name" value="{{ $a->name }}" maxlength="80" required></label>
                                                <label>Banco<input name="institution" value="{{ $a->institution }}" maxlength="80"></label>
                                                @if ($a->isCard())
                                                    <label>Fecha dia<input name="closing_day" type="number" min="1" max="31" value="{{ $a->closing_day }}" required></label>
                                                    <label>Vence dia<input name="due_day" type="number" min="1" max="31" value="{{ $a->due_day }}" required></label>
                                                    <label>Limite (R$)<input name="limit" value="{{ $input($a->limit_cents) }}" inputmode="decimal"></label>
                                                @else
                                                    <label>Saldo inicial (R$)<input name="opening_balance" value="{{ $input($a->opening_balance_cents) }}" inputmode="decimal"></label>
                                                    <div class="helper">O saldo inicial é o que havia antes do primeiro lançamento. Ajuste se o saldo daqui não bater com o do banco.</div>
                                                @endif
                                                <button type="submit">Salvar</button>
                                            </form>
                                            <form method="POST" action="{{ route('finance.accounts.archive', $a) }}" style="margin-top:12px;">
                                                @csrf<input type="hidden" name="archive" value="{{ $a->archived_at ? 0 : 1 }}">
                                                <button type="submit" class="secondary">{{ $a->archived_at ? 'Voltar a usar' : 'Arquivar (não uso mais)' }}</button>
                                            </form>
                                            <form method="POST" action="{{ route('finance.accounts.destroy', $a) }}" style="margin-top:8px;" onsubmit="return confirm('Excluir esta conta? Só dá se ela não tiver lançamentos.');">
                                                @csrf<button type="submit" class="danger">Excluir</button>
                                            </form>
                                        </div>
                                    </details>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </div>
</x-core::layout>
