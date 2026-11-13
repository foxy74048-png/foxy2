<x-core::layout title="Seleções para clientes" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>
    <x-slot:actions>
        @include('commerce.export.buttons')
        @include('commerce._add', ['label' => 'Nova seleção', 'href' => route('commerce.selections.create')])
    </x-slot:actions>

    @include('commerce.status')

    <p class="helper" style="margin-top:0;">Escolha algumas peças (as novidades, o que serve numa cliente, uma promoção) e mande um link só com elas. O link mostra o estoque de agora e a cliente pede pelo WhatsApp. Nada sai do estoque. Também dá para marcar as peças direto em <a href="{{ route('commerce.products') }}">Produtos</a>.</p>

    <form class="card form-grid" method="GET" action="{{ route('commerce.selections') }}" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); align-items:end;">
        <div>
            <label for="situacao">Situação</label>
            <select id="situacao" name="situacao">@foreach (['ativas' => 'Ativas', 'desativadas' => 'Desativadas', 'todas' => 'Todas'] as $k => $l)<option value="{{ $k }}" @selected($filters['situacao'] === $k)>{{ $l }}</option>@endforeach</select>
        </div>
        <div><button type="submit">Filtrar</button></div>
    </form>

    <div class="card" style="margin-top:16px;">
        @if ($selections->isEmpty())
            <div class="empty">Nenhuma seleção ainda. Crie a primeira em <a href="{{ route('commerce.selections.create') }}">Nova seleção</a>.</div>
        @else
            <div class="table-wrap"><table>
                <thead><tr><th>Seleção</th><th style="text-align:right;">Peças</th><th>Atualizada</th><th>Situação</th><th style="text-align:right;">Ações</th></tr></thead>
                <tbody>
                    @foreach ($selections as $s)
                        @php($link = $urls($s))
                        <tr>
                            <td><a href="{{ route('commerce.selections.show', $s) }}"><strong>{{ $s->title }}</strong></a>@if ($s->only_in_stock)<div class="helper">só o que tem em estoque</div>@endif</td>
                            <td style="text-align:right;">{{ count($s->product_ids ?? []) }}</td>
                            <td>{{ $s->updated_at->format('d/m/Y') }}</td>
                            <td><span @class(['badge', 'success' => $s->active])>{{ $s->active ? 'Ativa' : 'Desativada' }}</span></td>
                            <td style="text-align:right;white-space:nowrap;">
                                @if ($s->active)<a class="button secondary" href="https://wa.me/?text={{ rawurlencode($s->title.":\n".$link) }}" target="_blank" rel="noopener">Mandar no WhatsApp</a>@endif
                                <a class="button secondary" href="{{ route('commerce.selections.show', $s) }}">Editar</a>
                                <form method="POST" action="{{ route('commerce.selections.toggle', $s) }}" style="display:inline;">@csrf<button type="submit" class="secondary">{{ $s->active ? 'Desativar' : 'Reativar' }}</button></form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            <div style="margin-top:12px;">{{ $selections->links() }}</div>
        @endif
    </div>
</x-core::layout>
