<x-core::layout :title="'Estoque · '.$variant->sku" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>
    <x-slot:actions>@include('commerce.export.buttons') <a class="button secondary" href="{{ route('commerce.stock') }}">Voltar ao estoque</a></x-slot:actions>

    @include('commerce.status')
    @php($canAdjust = \App\Commerce\Access::allows($currentMembership, 'commerce.stock.adjust'))

    <div class="grid cards">
        <div class="card"><div class="muted">Produto</div><h3 style="margin:4px 0;">{{ $variant->fullName() }}</h3></div>
        @unless ($variant->is_kit)
            <div class="card"><div class="muted">Físico</div><h2 @style(['margin:4px 0', 'color:var(--danger-text)' => ($level?->on_hand ?? 0) < 0])>{{ $level?->on_hand ?? 0 }}</h2></div>
            <div class="card"><div class="muted">Reservado</div><h2 style="margin:4px 0;">{{ $level?->reserved ?? 0 }}</h2></div>
        @endunless
        <div class="card"><div class="muted">Disponível</div><h2 style="margin:4px 0;">{{ $available }}</h2></div>
    </div>

    @if ($variant->is_kit)
        <div class="card" style="margin-top:16px;">
            <h3 style="margin-top:0;">Kit: o estoque vem das peças</h3>
            <ul style="margin:0;padding-left:20px;line-height:1.8;">
                @foreach ($variant->components as $c)<li>{{ $c->quantity }} × <a href="{{ route('commerce.stock.show', $c->component) }}">{{ $c->component->sku }}</a></li>@endforeach
            </ul>
        </div>
    @elseif ($canAdjust)
        <div class="grid two" style="margin-top:16px;">
            <form class="card stack" method="POST" action="{{ route('commerce.stock.adjust', $variant) }}">
                @csrf
                <h3 style="margin:0;">Mexer no estoque</h3>
                <div>
                    <label for="type">O que aconteceu</label>
                    <select id="type" name="type">
                        <option value="entrada">Entrada (chegou mercadoria)</option>
                        <option value="saida">Saída (perda, uso, brinde)</option>
                        <option value="ajuste">Contagem (o físico passa a ser este número)</option>
                    </select>
                </div>
                <div>
                    <label for="quantity">Quantidade</label>
                    <input id="quantity" name="quantity" type="number" min="0" required>
                </div>
                <div>
                    <label for="reason">Motivo (opcional)</label>
                    <input id="reason" name="reason" maxlength="200">
                </div>
                <button type="submit">Salvar</button>
            </form>
            <form class="card stack" method="POST" action="{{ route('commerce.stock.minimum', $variant) }}">
                @csrf
                <h3 style="margin:0;">Estoque mínimo</h3>
                <p class="helper" style="margin:0;">Quando o disponível chegar neste número, a variante aparece como "Baixo".</p>
                <div>
                    <label for="min_stock">Mínimo</label>
                    <input id="min_stock" name="min_stock" type="number" min="0" value="{{ $level?->min_stock }}">
                </div>
                <button type="submit" class="secondary">Salvar mínimo</button>
            </form>
        </div>
    @endif

    <div class="card" style="margin-top:16px;">
        <h3 style="margin-top:0;">Histórico</h3>
        @if ($movements->isEmpty())
            <div class="empty">Nenhum movimento ainda.</div>
        @else
            <div class="table-wrap"><table>
                <thead><tr><th>Quando</th><th>Tipo</th><th>Quantidade</th><th>Físico depois</th><th>Quem</th><th>Motivo</th></tr></thead>
                <tbody>
                    @foreach ($movements as $m)
                        <tr>
                            <td>{{ $m->created_at->format('d/m/Y H:i') }}</td>
                            <td>{{ $m->label() }}</td>
                            <td>{{ $m->quantity > 0 ? '+'.$m->quantity : $m->quantity }}@if ($m->reserved_change) <span class="helper">(reserva {{ $m->reserved_change > 0 ? '+'.$m->reserved_change : $m->reserved_change }})</span>@endif</td>
                            <td>{{ $m->on_hand_after }}</td>
                            <td>{{ $m->user_id ? ($users[$m->user_id] ?? '—') : 'Sistema' }}</td>
                            <td>{{ $m->reason ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            <div style="margin-top:12px;">{{ $movements->links() }}</div>
        @endif
    </div>
</x-core::layout>
