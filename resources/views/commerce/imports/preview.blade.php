<x-core::layout title="Conferir planilha" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>
    <x-slot:actions><a class="button secondary" href="{{ route('commerce.imports') }}">Enviar outra planilha</a></x-slot:actions>
    @php($money = fn ($c) => \App\Commerce\Catalog\Money::format((int) $c))
    @php($errors_ = $preview['errors'])

    @include('commerce.status')

    <p class="muted" style="margin-top:0;"><b>{{ \App\Commerce\Http\ImportController::TYPES[$type] }}</b> · {{ $file }}. Confira abaixo; nada foi lançado ainda.</p>

    @if ($type === 'produtos')
        @php($groups = collect($preview['groups']))
        @php($ok = $groups->filter(fn ($g) => empty($g['error'])))
        <div class="grid cards">
            <div class="card"><div class="muted">Produtos novos</div><h2 style="margin:4px 0;">{{ $ok->whereNull('product_id')->count() }}</h2></div>
            <div class="card"><div class="muted">Já cadastrados (somam variações e estoque)</div><h2 style="margin:4px 0;">{{ $ok->whereNotNull('product_id')->count() }}</h2></div>
            <div class="card"><div class="muted">Variações · peças</div><h2 style="margin:4px 0;">{{ $ok->sum(fn ($g) => count($g['lines'])) }} · {{ $ok->sum(fn ($g) => collect($g['lines'])->sum('quantity')) }}</h2></div>
            <div class="card"><div class="muted">Com problema (não entram)</div><h2 @style(['margin:4px 0', 'color:var(--danger-text)' => count($errors_) + $groups->count() - $ok->count() > 0])>{{ count($errors_) + $groups->count() - $ok->count() }}</h2></div>
        </div>

        @if ($ok->isNotEmpty())
            <form class="card stack" method="POST" action="{{ route('commerce.imports.apply', [$type, $token]) }}" style="margin-top:16px;">
                @csrf
                <div>
                    <label>O que fazer com a quantidade da planilha</label>
                    <label style="display:flex;gap:8px;align-items:center;font-weight:400;"><input type="radio" name="stock_mode" value="somar" style="width:auto;" checked> <span><b>Somar</b> ao estoque que já está no sistema (é mercadoria que chegou)</span></label>
                    <label style="display:flex;gap:8px;align-items:center;font-weight:400;"><input type="radio" name="stock_mode" value="igualar" style="width:auto;"> <span><b>Deixar igual</b> à planilha (é a contagem do que eu tenho hoje)</span></label>
                </div>
                <div><button type="submit">Lançar {{ $ok->count() }} produto(s)</button></div>
            </form>
        @endif

        <div class="card" style="margin-top:16px;">
            <div class="table-wrap"><table>
                <thead><tr><th>Produto</th><th>Categoria</th><th>Variações (cor · tamanho · qtd.)</th><th style="text-align:right;">Peças</th><th style="text-align:right;">Preço</th><th style="text-align:right;">Custo</th></tr></thead>
                <tbody>
                    @foreach ($groups as $g)
                        <tr>
                            <td>
                                <strong>{{ $g['name'] }}</strong>
                                @if (! empty($g['error']))<div><span class="badge danger">{{ $g['error'] }}</span></div>
                                @elseif ($g['product_id'])<div><span class="badge">já existe{{ Str::lower($g['existing_name']) !== Str::lower($g['name']) ? ': '.$g['existing_name'] : '' }}</span></div>
                                @else<div><span class="badge success">novo</span></div>@endif
                                @if ($g['supplier'] || $g['mode'])<div class="helper">{{ $g['supplier'] }}{{ $g['mode'] ? ' · '.($g['mode'] === 'compra' ? 'comprado' : 'consignado') : '' }}</div>@endif
                            </td>
                            <td>{{ $g['category'] ?: '—' }}</td>
                            <td style="font-size:.9rem;">{{ collect($g['lines'])->map(fn ($l) => trim(implode(' · ', array_filter([$l['color'], $l['size']])).' ×'.$l['quantity']))->implode(', ') }}</td>
                            <td style="text-align:right;">{{ collect($g['lines'])->sum('quantity') }}</td>
                            <td style="text-align:right;white-space:nowrap;">{{ $g['sale_cents'] ? $money($g['sale_cents']) : '—' }}</td>
                            <td style="text-align:right;white-space:nowrap;">{{ $g['cost_cents'] ? $money($g['cost_cents']) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        </div>
    @else
        @php($rows = collect($preview['rows']))
        @php($receivable = $type === 'receber')
        <div class="grid cards">
            <div class="card"><div class="muted">Contas</div><h2 style="margin:4px 0;">{{ $rows->count() }}</h2></div>
            <div class="card"><div class="muted">Em aberto</div><h2 style="margin:4px 0;">{{ $money($rows->where('paid', false)->sum('amount_cents')) }}</h2></div>
            <div class="card"><div class="muted">Já {{ $receivable ? 'recebidas' : 'pagas' }} (histórico)</div><h2 style="margin:4px 0;">{{ $rows->where('paid', true)->count() }}</h2></div>
            <div class="card"><div class="muted">Linhas com problema (não entram)</div><h2 @style(['margin:4px 0', 'color:var(--danger-text)' => count($errors_) > 0])>{{ count($errors_) }}</h2></div>
        </div>

        @if ($rows->isNotEmpty())
            <form class="card" method="POST" action="{{ route('commerce.imports.apply', [$type, $token]) }}" style="margin-top:16px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                @csrf
                <button type="submit">Lançar {{ $rows->count() }} conta(s)</button>
                <span class="helper" style="margin:0;">{{ $receivable ? 'Clientes que não estão cadastradas entram em Clientes.' : 'Entram em Financeiro › A pagar e no fluxo de caixa.' }}</span>
            </form>
        @endif

        <div class="card" style="margin-top:16px;">
            <div class="table-wrap"><table>
                <thead><tr><th>Linha</th>@if ($receivable)<th>Cliente</th><th>WhatsApp</th>@else<th>Fornecedor</th><th>Categoria</th>@endif<th>Descrição</th><th style="text-align:right;">Valor</th><th>Vencimento</th><th>Situação</th></tr></thead>
                <tbody>
                    @foreach ($rows as $r)
                        <tr>
                            <td class="muted">{{ $r['row'] }}</td>
                            @if ($receivable)<td>{{ $r['customer'] }}</td><td>{{ $r['phone'] ?: '—' }}</td>
                            @else<td>{{ $r['supplier'] ?: '—' }}</td><td>{{ \App\Commerce\Finance\Payable::CATEGORIES[$r['category']] ?? $r['category'] }}</td>@endif
                            <td>{{ $r['description'] }}</td>
                            <td style="text-align:right;white-space:nowrap;">{{ $money($r['amount_cents']) }}</td>
                            <td>{{ \Illuminate\Support\Carbon::parse($r['due_date'])->format('d/m/Y') }}</td>
                            <td>@if ($r['paid'])<span class="badge success">{{ $receivable ? 'recebida' : 'paga' }} {{ \Illuminate\Support\Carbon::parse($r['paid_on'])->format('d/m') }}</span>@else<span class="badge">em aberto</span>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        </div>
    @endif

    @if ($errors_)
        <div class="card" style="margin-top:16px;">
            <h3 style="margin-top:0;">Linhas que não vão entrar</h3>
            <ul style="margin:0;padding-left:18px;">
                @foreach (array_slice($errors_, 0, 200, true) as $line => $message)<li>Linha {{ $line }}: {{ $message }}</li>@endforeach
            </ul>
            @if (count($errors_) > 200)<p class="helper">e mais {{ count($errors_) - 200 }}.</p>@endif
            <p class="helper" style="margin-bottom:0;">Corrija na planilha e envie de novo — ou lance o resto agora e cadastre estas na mão.</p>
        </div>
    @endif
</x-core::layout>
