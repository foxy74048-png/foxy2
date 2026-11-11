<x-core::layout title="Consignação" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>
    <x-slot:actions>@include('commerce.export.buttons')@include('commerce._add', ['label' => 'Nova maleta', 'href' => route('commerce.consignments.create')])</x-slot:actions>
    @php($money = fn ($c) => \App\Commerce\Catalog\Money::format($c))

    @include('commerce.status')
    @include('commerce.consignments._tabs', ['tab' => 'enviadas'])

    <div class="grid cards">
        <div class="card"><div class="muted">Maletas com as pessoas</div><h2 style="margin:4px 0;">{{ $summary['open'] }}</h2><div class="helper">{{ $summary['late'] ? $summary['late'].' com o acerto atrasado' : 'nenhuma atrasada' }}</div></div>
        <div class="card"><div class="muted">Peças fora da loja</div><h2 style="margin:4px 0;">{{ $summary['pieces'] }}</h2><div class="helper">reservadas: não aparecem para venda até o acerto</div></div>
        <div class="card"><div class="muted">Valor das peças fora</div><h2 style="margin:4px 0;">{{ $money($summary['value']) }}</h2><div class="helper">pelo preço de venda</div></div>
    </div>

    <form class="card" method="GET" style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
        <div><label for="situacao">Situação</label><select id="situacao" name="situacao">@foreach (['abertas' => 'Com a pessoa', 'acertadas' => 'Acertadas', 'todas' => 'Todas'] as $k => $l)<option value="{{ $k }}" @selected($filters['situacao'] === $k)>{{ $l }}</option>@endforeach</select></div>
        <div><label for="q">Pessoa</label><input id="q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Nome"></div>
        <div><button type="submit" class="secondary">Filtrar</button></div>
    </form>

    <div class="card" style="margin-top:16px;">
        @if ($consignments->isEmpty())
            <div class="empty">
                <p style="margin-top:0;">Nenhuma maleta {{ $filters['situacao'] === 'abertas' ? 'com as pessoas agora' : 'por aqui' }}.</p>
                <p class="helper">Maleta (ou sacolinha) é quando você deixa peças com uma cliente para ela escolher em casa, ou com uma revendedora que vende e ganha comissão. As peças ficam reservadas; no acerto, o que voltou fica disponível de novo e o que ficou vira venda — paga na hora ou em parcelas a receber.</p>
                <a class="button" href="{{ route('commerce.consignments.create') }}">+ Nova maleta</a>
            </div>
        @else
            <div class="table-wrap"><table>
                <thead><tr><th>Nº</th><th>Pessoa</th><th>Saiu</th><th>Volta até</th><th style="text-align:right;">Peças</th><th style="text-align:right;">Valor</th><th>Situação</th><th></th></tr></thead>
                <tbody>
                    @foreach ($consignments as $c)
                        <tr>
                            <td>#{{ $c->number }}</td>
                            <td><a href="{{ route('commerce.consignments.show', $c) }}"><strong>{{ $c->customer?->name }}</strong></a><div class="helper">{{ $c->kind === 'revendedora' ? 'Revendedora · '.rtrim(rtrim(number_format($c->commission_percent, 2, ',', ''), '0'), ',').'%' : 'Cliente' }}</div></td>
                            <td>{{ $c->sent_on->format('d/m/Y') }}</td>
                            <td>{{ $c->due_on?->format('d/m/Y') ?? '—' }}@if ($c->isLate())<div class="helper" style="color:var(--danger-text);">atrasado</div>@endif</td>
                            <td style="text-align:right;">{{ $c->pieces() }}</td>
                            <td style="text-align:right;white-space:nowrap;">{{ $money($c->valueCents()) }}</td>
                            <td><span @class(['badge', 'success' => $c->status === 'acertada', 'danger' => $c->isLate()])>{{ \App\Commerce\Consignment\Consignment::STATUSES[$c->status] }}</span></td>
                            <td style="text-align:right;"><a class="button secondary" href="{{ route('commerce.consignments.show', $c) }}">{{ $c->status === 'aberta' ? 'Abrir / acerto' : 'Abrir' }}</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            <div style="margin-top:12px;">{{ $consignments->links() }}</div>
        @endif
    </div>
</x-core::layout>
