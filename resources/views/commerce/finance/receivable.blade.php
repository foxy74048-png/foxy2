<x-core::layout title="Conta a receber" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>
    <x-slot:actions><a class="button secondary" href="{{ route('commerce.finance') }}">Voltar ao financeiro</a></x-slot:actions>
    @php($money = fn ($c) => \App\Commerce\Catalog\Money::format($c))

    @include('commerce.status')

    <div class="grid cards">
        <div class="card"><div class="muted">Valor</div><h2 style="margin:4px 0;">{{ $money($r->amount_cents) }}</h2><div class="helper">{{ $r->installmentLabel() }}</div></div>
        <div class="card"><div class="muted">Vencimento</div><h2 style="margin:4px 0;font-size:1.2rem;">{{ $r->due_date->format('d/m/Y') }}</h2>@if ($r->isOverdue())<div class="helper" style="color:var(--danger-text);">{{ $r->daysLate() }} dia(s) de atraso</div>@endif</div>
        <div class="card"><div class="muted">Situação</div><h2 style="margin:4px 0;font-size:1.2rem;">{{ $r->statusLabel() }}</h2>@if ($r->received_at)<div class="helper">{{ $money($r->received_cents) }} em {{ $r->received_at->format('d/m/Y') }}{{ $r->payment_method ? ' · '.(\App\Commerce\Http\FinanceController::METHODS[$r->payment_method] ?? $r->payment_method) : '' }}</div>@endif</div>
        <div class="card"><div class="muted">Cliente</div><h2 style="margin:4px 0;font-size:1.1rem;">@if ($r->customer)<a href="{{ route('commerce.customers.show', $r->customer) }}">{{ $r->customer->name }}</a>@else — @endif</h2><div class="helper">{{ $r->customer?->phone ?: 'sem WhatsApp' }}@if ($r->order) · <a href="{{ route('commerce.orders.show', $r->order) }}">pedido #{{ $r->order->number }}</a>@endif</div></div>
    </div>

    @if ($r->isOpen())
        <div class="grid two" style="margin-top:16px;">
            <form class="card stack" method="POST" action="{{ route('commerce.finance.receivables.receive', $r) }}">
                @csrf
                <h3 style="margin:0;">Registrar recebimento</h3>
                <div class="form-grid">
                    <div><label for="amount">Valor recebido (R$)</label><input id="amount" name="amount" value="{{ \App\Commerce\Catalog\Money::input($r->amount_cents) }}" inputmode="decimal"></div>
                    <div><label for="date">Data</label><input id="date" name="date" type="date" value="{{ today()->toDateString() }}"></div>
                    <div><label for="method">Forma</label><select id="method" name="method"><option value="">—</option>@foreach (\App\Commerce\Http\FinanceController::METHODS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                </div>
                <div class="helper">Recebeu só uma parte? Informe o valor: o que faltar fica em aberto, com o mesmo vencimento.</div>
                <div><button type="submit">Registrar</button></div>
            </form>

            <div class="card stack">
                <h3 style="margin:0;">Cobrar pelo WhatsApp</h3>
                @if ($r->customer?->phone)
                    <p class="helper" style="margin:0;">Escolha a mensagem (os textos se editam em <a href="{{ route('commerce.whatsapp') }}">WhatsApp</a>). Com a loja conectada, sai pelo número dela; sem conexão, abre o seu WhatsApp com o texto pronto.</p>
                    <form method="POST" action="{{ route('commerce.finance.receivables.charge', $r) }}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
                        @csrf
                        <div style="min-width:220px;"><label for="kind">Mensagem</label><select id="kind" name="kind">@foreach (\App\Commerce\Finance\WhatsAppMessage::KINDS as $k => $l)<option value="{{ $k }}" @selected($k === ($r->isOverdue() ? 'lembrete_atraso' : 'cobranca'))>{{ $l }}</option>@endforeach</select></div>
                        <button type="submit">Enviar cobrança</button>
                    </form>
                @else
                    <div class="alert info" style="margin:0;">Esta conta não tem cliente com WhatsApp. @if ($r->customer)<a href="{{ route('commerce.customers.show', $r->customer) }}">Cadastre o telefone</a>.@endif</div>
                @endif
                @if ($messages->isNotEmpty())
                    <div>
                        <strong>Já enviadas</strong>
                        @foreach ($messages as $m)
                            <div class="helper">{{ $m->created_at->timezone('America/Sao_Paulo')->format('d/m H:i') }} · {{ \App\Commerce\Finance\WhatsAppMessage::KINDS[$m->kind] ?? $m->kind }} · {{ \App\Commerce\Finance\WhatsAppMessage::STATUSES[$m->status] ?? $m->status }}{{ $m->error ? ': '.$m->error : '' }}</div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <form class="card form-grid" method="POST" action="{{ route('commerce.finance.receivables.update', $r) }}" style="margin-top:16px;align-items:end;">
            @csrf
            <div style="grid-column:1/-1;"><h3 style="margin:0;">Editar conta</h3></div>
            <div><label for="e-desc">Descrição</label><input id="e-desc" name="description" value="{{ old('description', $r->description) }}" required maxlength="160"></div>
            <div><label for="e-amount">Valor (R$)</label><input id="e-amount" name="amount" value="{{ old('amount', \App\Commerce\Catalog\Money::input($r->amount_cents)) }}" required inputmode="decimal"></div>
            <div><label for="e-due">Vencimento</label><input id="e-due" name="due_date" type="date" value="{{ old('due_date', $r->due_date->toDateString()) }}" required></div>
            <div style="grid-column:1/-1;"><label for="e-notes">Observações</label><textarea id="e-notes" name="notes" rows="2" maxlength="2000">{{ old('notes', $r->notes) }}</textarea></div>
            <div style="display:flex;gap:8px;"><button type="submit">Salvar</button></div>
        </form>
        <form method="POST" action="{{ route('commerce.finance.receivables.cancel', $r) }}" style="margin-top:12px;" onsubmit="return confirm('Cancelar esta conta? Ela sai do a receber (o histórico fica).');">@csrf<button type="submit" class="danger">Cancelar conta</button></form>
    @elseif ($r->status === 'recebido')
        <form method="POST" action="{{ route('commerce.finance.receivables.reopen', $r) }}" style="margin-top:16px;" onsubmit="return confirm('Desfazer o recebimento e deixar a conta em aberto de novo?');">@csrf<button type="submit" class="secondary">Desfazer recebimento</button></form>
    @endif

    @if ($siblings->count() > 1)
        <div class="card" style="margin-top:16px;">
            <h3 style="margin-top:0;">Todas as parcelas deste pedido</h3>
            <div class="table-wrap"><table>
                <thead><tr><th>Parcela</th><th>Vencimento</th><th style="text-align:right;">Valor</th><th>Situação</th></tr></thead>
                <tbody>@foreach ($siblings as $s)<tr @if ($s->id === $r->id) style="font-weight:600;" @endif><td><a href="{{ route('commerce.finance.receivables.show', $s) }}">{{ $s->installmentLabel() ?: 'Única' }}</a></td><td>{{ $s->due_date->format('d/m/Y') }}</td><td style="text-align:right;">{{ $money($s->amount_cents) }}</td><td><span @class(['badge', 'success' => $s->status === 'recebido', 'danger' => $s->isOverdue()])>{{ $s->statusLabel() }}</span></td></tr>@endforeach</tbody>
            </table></div>
        </div>
    @endif
    @if ($r->notes)<p class="helper" style="margin-top:12px;white-space:pre-line;">{{ $r->notes }}</p>@endif
</x-core::layout>
