<x-core::layout title="Anúncios" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>
    <x-slot:actions>@include('commerce.export.buttons') @if ($connections->isNotEmpty())<a class="button" href="{{ route('commerce.listings.create') }}">Novo anúncio</a>@endif</x-slot:actions>
    @php($money = fn ($c) => \App\Commerce\Catalog\Money::format($c))

    @include('commerce.status')

    @if ($connections->isEmpty())
        <div class="card"><div class="empty">Nenhum marketplace conectado. Comece em <a href="{{ route('commerce.channels') }}">Marketplaces</a>.</div></div>
    @else
        <div class="grid cards">
            @foreach (['active' => 'No ar', 'error' => 'Com erro', 'paused' => 'Pausados', 'draft' => 'Rascunhos'] as $k => $label)
                <a class="card" href="{{ route('commerce.listings', ['situacao' => $k]) }}"><div class="muted">{{ $label }}</div><h2 @style(['margin:4px 0', 'color:var(--danger-text)' => $k === 'error' && ($counts[$k] ?? 0) > 0])>{{ $counts[$k] ?? 0 }}</h2></a>
            @endforeach
        </div>

        <form class="card form-grid" method="GET" action="{{ route('commerce.listings') }}" style="margin-top:16px;grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); align-items:end;">
            <div><label for="busca">Título</label><input id="busca" name="busca" value="{{ $filters['busca'] ?? '' }}"></div>
            <div>
                <label for="conexao">Conta</label>
                <select id="conexao" name="conexao"><option value="">Todas</option>@foreach ($connections as $c)<option value="{{ $c->id }}" @selected(($filters['conexao'] ?? '') === $c->id)>{{ $c->label() }} · {{ $c->name }}</option>@endforeach</select>
            </div>
            <div>
                <label for="situacao">Situação</label>
                <select id="situacao" name="situacao"><option value="">Todas</option>@foreach (\App\Commerce\Channels\Listing::STATUSES as $k => $l)<option value="{{ $k }}" @selected(($filters['situacao'] ?? '') === $k)>{{ $l }}</option>@endforeach</select>
            </div>
            <div><button type="submit">Filtrar</button></div>
        </form>

        <div class="card" style="margin-top:16px;">
            @if ($listings->isEmpty())
                <div class="empty">Nenhum anúncio com esses filtros.</div>
            @else
                <div class="table-wrap"><table>
                    <thead><tr><th></th><th>Anúncio</th><th>Canal</th><th>Variantes</th><th style="text-align:right;">Preço</th><th>Situação</th><th style="text-align:right;">Ações</th></tr></thead>
                    <tbody>
                        @foreach ($listings as $l)
                            <tr>
                                <td style="width:56px;">@if ($l->product->images->first()?->file)<img src="{{ $l->product->images->first()->file->url() }}" alt="" style="width:44px;height:44px;object-fit:cover;border-radius:8px;">@endif</td>
                                <td><a href="{{ route('commerce.listings.edit', $l) }}"><strong>{{ $l->title }}</strong></a>@if ($l->last_error)<div class="helper" style="color:var(--danger-text);">{{ \Illuminate\Support\Str::limit($l->last_error, 120) }}</div>@endif</td>
                                <td>{{ $l->connection->label() }}<div class="helper">{{ $l->external_id ?? '—' }}</div></td>
                                <td>{{ $l->variants->count() }}</td>
                                <td style="text-align:right;white-space:nowrap;">{{ $money($l->price_cents) }}</td>
                                <td><span @class(['badge', 'success' => $l->status === 'active', 'danger' => $l->status === 'error'])>{{ $l->statusLabel() }}</span></td>
                                <td style="text-align:right;white-space:nowrap;">
                                    <a class="button secondary" href="{{ route('commerce.listings.edit', $l) }}">Editar</a>
                                    @if (in_array($l->status, ['active', 'paused'], true))
                                        <form method="POST" action="{{ route('commerce.listings.pause', $l) }}" style="display:inline;">
                                            @csrf<input type="hidden" name="paused" value="{{ $l->status === 'active' ? 1 : 0 }}">
                                            <button type="submit" class="secondary">{{ $l->status === 'active' ? 'Pausar' : 'Reativar' }}</button>
                                        </form>
                                    @elseif (in_array($l->status, ['draft', 'error'], true))
                                        <form method="POST" action="{{ route('commerce.listings.destroy', $l) }}" style="display:inline;" onsubmit="return confirm('Apagar este rascunho de anúncio? Ele ainda não está no canal.');">
                                            @csrf<button type="submit" class="secondary">Apagar</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
                <div style="margin-top:12px;">{{ $listings->links() }}</div>
            @endif
        </div>
    @endif
</x-core::layout>
