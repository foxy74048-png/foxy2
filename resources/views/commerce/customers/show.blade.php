<x-core::layout :title="$customer->name" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>
    <x-slot:actions>@include('commerce.export.buttons')<a class="button secondary" href="{{ route('commerce.customers') }}">Voltar aos clientes</a><a class="button" href="{{ route('commerce.orders.create', ['cliente' => $customer->id]) }}">Novo pedido para ela</a></x-slot:actions>
    @php($money = fn ($c) => \App\Commerce\Catalog\Money::format($c))

    @include('commerce.status')

    <div class="grid cards">
        <div class="card"><div class="muted">Total comprado</div><h2 style="margin:4px 0;">{{ $money($spent) }}</h2></div>
        <div class="card"><div class="muted">Pedidos</div><h2 style="margin:4px 0;">{{ $orders->whereIn('status', \App\Commerce\Orders\Order::SOLD)->count() }}</h2></div>
        <div class="card"><div class="muted">Ticket médio</div><h2 style="margin:4px 0;">{{ $money($ticket) }}</h2></div>
        <div class="card"><div class="muted">Última compra</div><h2 style="margin:4px 0;font-size:1.2rem;">{{ $orders->first()?->placed_at->format('d/m/Y') ?? '—' }}</h2></div>
    </div>

    <div class="card" style="margin-top:16px;">
        <h3 style="margin-top:0;">Pedidos</h3>
        <div class="table-wrap"><table>
            <thead><tr><th>Nº</th><th>Data</th><th>Canal</th><th>Situação</th><th style="text-align:right;">Total</th></tr></thead>
            <tbody>@foreach ($orders as $o)<tr><td><a href="{{ route('commerce.orders.show', $o) }}">#{{ $o->number }}</a></td><td>{{ $o->placed_at->format('d/m/Y') }}</td><td>{{ $o->channelLabel() }}</td><td>{{ $o->statusLabel() }}</td><td style="text-align:right;">{{ $money($o->total_cents) }}</td></tr>@endforeach</tbody>
        </table></div>
    </div>

    @php($open = $receivables->where('status', 'aberto'))
    @if (\App\Commerce\Access::allows($currentMembership, 'commerce.finance.manage'))
    <div class="card" style="margin-top:16px;">
        <div class="toolbar"><h3 style="margin:0;">Contas a receber <span class="muted">({{ $open->count() }} em aberto · {{ $money($open->sum('amount_cents')) }})</span></h3>
            @if ($customer->phone)<a class="button secondary" href="https://wa.me/{{ \App\Platform\Messaging\WhatsAppSender::normalize($customer->phone) }}" target="_blank" rel="noopener">Abrir conversa no WhatsApp</a>@endif</div>
        @if ($receivables->isEmpty())
            <div class="empty">Nada a receber desta cliente. Vendas no crediário/fiado aparecem aqui com as parcelas.</div>
        @else
            <div class="table-wrap"><table>
                <thead><tr><th>Vencimento</th><th>Descrição</th><th style="text-align:right;">Valor</th><th>Situação</th><th></th></tr></thead>
                <tbody>
                    @foreach ($receivables as $r)
                        <tr>
                            <td>{{ $r->due_date->format('d/m/Y') }}</td>
                            <td><a href="{{ route('commerce.finance.receivables.show', $r) }}">{{ $r->description }}</a> <span class="muted">{{ $r->installmentLabel() }}</span></td>
                            <td style="text-align:right;white-space:nowrap;">{{ $money($r->amount_cents) }}</td>
                            <td><span @class(['badge', 'success' => $r->status === 'recebido', 'danger' => $r->isOverdue()])>{{ $r->statusLabel() }}</span></td>
                            <td style="text-align:right;">@if ($r->isOpen())<form method="POST" action="{{ route('commerce.finance.receivables.charge', $r) }}" style="display:inline;">@csrf<button type="submit" class="secondary">Cobrar no WhatsApp</button></form>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
        @if ($messages->isNotEmpty())
            <h4 style="margin-bottom:6px;">Mensagens enviadas</h4>
            @foreach ($messages as $m)
                <div class="helper">{{ $m->created_at->timezone('America/Sao_Paulo')->format('d/m H:i') }} · {{ \App\Commerce\Finance\WhatsAppMessage::KINDS[$m->kind] ?? $m->kind }} · {{ \App\Commerce\Finance\WhatsAppMessage::STATUSES[$m->status] ?? $m->status }}{{ $m->error ? ': '.$m->error : '' }}</div>
            @endforeach
        @endif
    </div>
    @endif

    <form class="card form-grid" method="POST" action="{{ route('commerce.customers.update', $customer) }}" style="margin-top:16px;">
        @csrf
        <div style="grid-column: 1 / -1;"><h3 style="margin:0;">Dados</h3></div>
        <div><label for="name">Nome</label><input id="name" name="name" value="{{ old('name', $customer->name) }}" required maxlength="255"></div>
        <div><label for="phone">WhatsApp</label><input id="phone" name="phone" value="{{ old('phone', $customer->phone) }}" maxlength="30"></div>
        <div><label for="email">E-mail</label><input id="email" name="email" type="email" value="{{ old('email', $customer->email) }}" maxlength="255"></div>
        <div><label for="document">CPF/CNPJ</label><input id="document" name="document" value="{{ old('document', $customer->document) }}" maxlength="20"></div>
        <div><label for="city">Cidade</label><input id="city" name="city" value="{{ old('city', $customer->city) }}" maxlength="80"></div>
        <div><label for="state">UF</label><input id="state" name="state" value="{{ old('state', $customer->state) }}" maxlength="2"></div>
        <div style="grid-column: 1 / -1;"><label for="notes">Observações</label><textarea id="notes" name="notes" rows="2" maxlength="2000">{{ old('notes', $customer->notes) }}</textarea></div>
        <div><button type="submit">Salvar cliente</button></div>
    </form>
</x-core::layout>
