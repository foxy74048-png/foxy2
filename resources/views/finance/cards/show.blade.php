<x-core::layout :title="'Fatura '.$card->name" product="Foxy Finanças" :subtitle="$currentWallet->name">
    <x-slot:nav>@include('finance.nav')</x-slot:nav>
    <x-slot:actions>@include('platform.export-buttons', ['extra' => ['fatura' => $invoice['month']]])</x-slot:actions>
    @php($money = fn ($c) => \App\Platform\Support\Money::format($c))
    @php($monthName = fn ($m) => \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $m.'-01')->format('m/Y'))
    @php($status = $invoice['status'])

    @include('finance.status')

    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
        <a class="button secondary" href="{{ route('finance.cards.show', [$card, 'fatura' => $months[0]]) }}" aria-label="Fatura anterior">‹</a>
        <h2 style="margin:0;font-size:1.2rem;">Fatura de {{ $monthName($invoice['month']) }}</h2>
        <a class="button secondary" href="{{ route('finance.cards.show', [$card, 'fatura' => $months[1]]) }}" aria-label="Fatura seguinte">›</a>
        @if ($invoice['month'] !== $openMonth)<a class="helper" href="{{ route('finance.cards.show', [$card, 'fatura' => $openMonth]) }}">ir para a aberta</a>@endif
        <a class="helper" href="{{ route('finance.cards') }}" style="margin-left:auto;">todas as faturas</a>
    </div>

    <div class="grid cards">
        <div class="card"><div class="muted">Gastos nesta fatura</div><h2 style="margin:4px 0;">{{ $money($invoice['charges']) }}</h2><div class="helper">compras menos estornos</div></div>
        <div class="card"><div class="muted">Pago</div><h2 style="margin:4px 0;">{{ $money($invoice['payments']) }}</h2></div>
        <div class="card"><div class="muted">Falta pagar</div><h2 style="margin:4px 0;{{ $status === 'atrasada' ? 'color:var(--danger-text);' : '' }}">{{ $money($invoice['remaining']) }}</h2></div>
        <div class="card">
            <div class="muted">Situação</div>
            <h2 style="margin:4px 0;font-size:1.1rem;"><span @class(['badge', 'success' => $status === 'paga', 'danger' => $status === 'atrasada'])>{{ \App\Finance\Accounts\CardInvoices::STATUSES[$status] }}</span></h2>
            <div class="helper">fecha em {{ $invoice['closing']->format('d/m/Y') }} · vence em {{ $invoice['due']->format('d/m/Y') }}</div>
        </div>
    </div>

    @if ($canManage && $invoice['remaining'] > 0 && in_array($status, ['fechada', 'parcial', 'atrasada', 'aberta'], true))
        <form class="card form-grid" method="POST" action="{{ route('finance.cards.pay', $card) }}" style="margin-top:16px;align-items:end;">
            @csrf
            <input type="hidden" name="fatura" value="{{ $invoice['month'] }}">
            <div style="grid-column:1/-1;">
                <h3 style="margin:0;">Pagar esta fatura</h3>
                <p class="helper" style="margin:4px 0 0;">Registra que você pagou (a Foxy não paga nada por você): vira uma transferência da conta para o cartão. Pagou só uma parte? Ponha o valor pago.</p>
            </div>
            @if ($payFrom->isEmpty())
                <div class="alert info" style="grid-column:1/-1;">Cadastre a conta de onde sai o pagamento em <a href="{{ route('finance.accounts') }}#nova-conta">Contas e cartões</a>.</div>
            @else
                <div><label for="p-from">Saiu da conta</label><select id="p-from" name="from_account_id">@foreach ($payFrom as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></div>
                <div><label for="p-amount">Valor pago (R$)</label><input id="p-amount" name="amount" value="{{ old('amount', \App\Platform\Support\Money::input($invoice['remaining'])) }}" inputmode="decimal" required></div>
                <div><label for="p-date">Data</label><input id="p-date" name="date" type="date" value="{{ old('date', today()->toDateString()) }}" required></div>
                <div><button type="submit">Registrar pagamento</button></div>
            @endif
        </form>
    @endif

    <div class="card" style="margin-top:16px;">
        <h3 style="margin-top:0;">O que está nesta fatura</h3>
        @php($charges = $invoice['items']->where('account_id', $card->id))
        @php($payments = $invoice['items']->where('to_account_id', $card->id))
        @if ($charges->isEmpty())
            <div class="empty">Nenhuma compra nesta fatura.</div>
        @else
            <div class="table-wrap"><table>
                <thead><tr><th>Data</th><th>Descrição</th><th>Categoria</th><th style="text-align:right;">Valor</th>@if ($canManage)<th></th>@endif</tr></thead>
                <tbody>
                    @foreach ($charges as $t)
                        <tr>
                            <td style="white-space:nowrap;">{{ $t->date->format('d/m/Y') }}</td>
                            <td><strong>{{ $t->description }}</strong> @if ($label = $t->seriesLabel())<span class="muted">{{ $label }}</span>@endif</td>
                            <td>{{ $t->isTransfer() ? 'Transferência' : ($t->category?->fullName() ?? '—') }}</td>
                            <td style="text-align:right;white-space:nowrap;{{ $t->kind === 'recebimento' ? 'color:var(--success-text);' : '' }}">{{ $t->kind === 'recebimento' ? '+ estorno ' : '' }}{{ $money($t->amount_cents) }}</td>
                            @if ($canManage)<td style="text-align:right;"><a class="button secondary" href="{{ route('finance.transactions.edit', $t) }}">Editar</a></td>@endif
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif

        @if ($payments->isNotEmpty())
            <h3>Pagamentos</h3>
            <div class="table-wrap"><table>
                <tbody>
                    @foreach ($payments as $t)
                        <tr><td style="white-space:nowrap;">{{ $t->date->format('d/m/Y') }}</td><td>{{ $t->description }}<div class="helper">de {{ $t->account?->name }}</div></td><td style="text-align:right;">{{ $money($t->amount_cents) }}</td>@if ($canManage)<td style="text-align:right;"><a class="button secondary" href="{{ route('finance.transactions.edit', $t) }}">Editar</a></td>@endif</tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </div>
</x-core::layout>
