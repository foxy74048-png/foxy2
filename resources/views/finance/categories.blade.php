<x-core::layout title="Categorias" product="Foxy Finanças" :subtitle="$currentWallet->name">
    <x-slot:nav>@include('finance.nav')</x-slot:nav>
    <x-slot:actions>@include('platform.export-buttons')@if ($canManage)@include('finance._add', ['label' => 'Nova categoria', 'target' => 'nova-categoria'])@endif</x-slot:actions>

    @include('finance.status')

    @if ($canManage)
        <form class="card form-grid" id="nova-categoria" method="POST" action="{{ route('finance.categories.store') }}" style="align-items:end;">
            @csrf
            <div style="grid-column:1/-1;"><h3 style="margin:0;">Nova categoria</h3><p class="helper" style="margin:4px 0 0;">Deixe o grupo vazio para criar um grupo novo; escolha um grupo para criar dentro dele (ex.: Lazer › Cinema).</p></div>
            <div><label for="c-kind">Tipo</label><select id="c-kind" name="kind">@foreach (\App\Finance\Categories\Category::KINDS as $k => $l)<option value="{{ $k }}" @selected(old('kind') === $k)>{{ $l }}</option>@endforeach</select></div>
            <div><label for="c-name">Nome</label><input id="c-name" name="name" value="{{ old('name') }}" required maxlength="60"></div>
            <div><label for="c-parent">Dentro do grupo</label><select id="c-parent" name="parent_id"><option value="">— é um grupo —</option>@foreach ($roots as $r)<option value="{{ $r->id }}" @selected(old('parent_id') === $r->id)>{{ \App\Finance\Categories\Category::KINDS[$r->kind] }}: {{ $r->name }}</option>@endforeach</select></div>
            <div><button type="submit">Criar</button></div>
        </form>
    @endif

    <div class="grid two" style="align-items:start;margin-top:16px;">
        @foreach (\App\Finance\Categories\Category::KINDS as $kind => $label)
            <div class="card">
                <h3 style="margin-top:0;">{{ $label }}s</h3>
                <div class="table-wrap"><table>
                    <thead><tr><th>Categoria</th><th style="text-align:right;">Lançamentos</th>@if ($canManage)<th></th>@endif</tr></thead>
                    <tbody>
                        @foreach ($trees[$kind] as $node)
                            @foreach (collect([$node['category']])->merge($node['children']) as $c)
                                <tr @if ($c->hidden_at) style="opacity:.55;" @endif>
                                    <td @if ($c->parent_id) style="padding-left:28px;" @endif>
                                        @if ($c->parent_id)<span class="muted">›</span> {{ $c->name }}@else<strong>{{ $c->name }}</strong>@endif
                                        @if ($c->hidden_at) <span class="badge">escondida</span>@endif
                                    </td>
                                    <td style="text-align:right;"><a href="{{ route('finance.transactions', ['categoria' => $c->id, 'de' => '2000-01-01']) }}">{{ (int) ($counts[$c->id] ?? 0) }}</a></td>
                                    @if ($canManage)
                                        <td style="text-align:right;white-space:nowrap;">
                                            <details style="display:inline-block;text-align:left;">
                                                <summary class="button secondary" style="list-style:none;cursor:pointer;">Editar</summary>
                                                <div class="card" style="position:absolute;right:24px;z-index:5;min-width:300px;margin-top:6px;box-shadow:var(--shadow);">
                                                    <form method="POST" action="{{ route('finance.categories.update', $c) }}" class="stack">
                                                        @csrf
                                                        <label>Nome<input name="name" value="{{ $c->name }}" maxlength="60" required></label>
                                                        <label>Dentro do grupo
                                                            <select name="parent_id"><option value="">— é um grupo —</option>@foreach ($roots->where('kind', $c->kind)->where('id', '!=', $c->id) as $r)<option value="{{ $r->id }}" @selected($c->parent_id === $r->id)>{{ $r->name }}</option>@endforeach</select>
                                                        </label>
                                                        <button type="submit">Salvar</button>
                                                    </form>
                                                    <form method="POST" action="{{ route('finance.categories.hide', $c) }}" style="margin-top:12px;">
                                                        @csrf<input type="hidden" name="hide" value="{{ $c->hidden_at ? 0 : 1 }}">
                                                        <button type="submit" class="secondary">{{ $c->hidden_at ? 'Mostrar de novo' : 'Esconder dos formulários' }}</button>
                                                    </form>
                                                    <form method="POST" action="{{ route('finance.categories.merge', $c) }}" class="stack" style="margin-top:12px;" onsubmit="return confirm('Juntar? Os lançamentos passam para a outra categoria e esta deixa de existir.');">
                                                        @csrf
                                                        <label>Juntar com
                                                            <select name="target_id" required><option value="">— escolha —</option>@foreach ($all->where('kind', $c->kind)->where('id', '!=', $c->id)->where('parent_id', '!=', $c->id) as $o)<option value="{{ $o->id }}">{{ $o->fullName() }}</option>@endforeach</select>
                                                        </label>
                                                        <button type="submit" class="danger">Juntar</button>
                                                    </form>
                                                </div>
                                            </details>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table></div>
            </div>
        @endforeach
    </div>
</x-core::layout>
