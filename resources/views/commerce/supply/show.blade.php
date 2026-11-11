<x-core::layout :title="'Remessa '.$batch->label()" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>
    <x-slot:actions>@if ($batch->status !== 'rascunho')@include('commerce.export.buttons')@endif<a class="button secondary" href="{{ route('commerce.supply') }}">Voltar às remessas</a></x-slot:actions>
    @php($money = fn ($c) => \App\Commerce\Catalog\Money::format($c))
    @php($input = fn ($c) => \App\Commerce\Catalog\Money::input($c))

    @include('commerce.status')

    @if ($batch->status === 'rascunho')
        @php($groups = $batch->groups ?? [])
        @php($pieces = collect($groups)->sum(fn ($g) => collect($g['lines'])->sum('quantity')))
        @php($recognized = collect($groups)->sum(fn ($g) => collect($g['lines'])->where('known', true)->sum('quantity')))
        <div class="alert info" style="margin-bottom:16px;">Lemos <b>{{ $pieces }} peças</b> em <b>{{ count($groups) }} produto(s)</b>{{ $batch->source === 'nfe' ? ' da nota fiscal' : '' }}{{ $batch->total_cents ? ', total de '.$money($batch->total_cents) : '' }}. Confira nomes, cores, tamanhos e preços. Nada entra no estoque até você clicar em <b>Lançar no estoque</b>.
            @if ($knownSupplier)<br>✔ Fornecedora já conhecida{{ $batch->supplierRecord->document ? ' (CNPJ '.$batch->supplierRecord->documentLabel().')' : '' }}: <b>{{ $recognized }} de {{ $pieces }} peças</b> reconhecidas das remessas anteriores, já no produto, cor e tamanho que você conferiu.@endif
            @if (count($batch->installments ?? []) > 1)<br>A nota tem {{ count($batch->installments) }} parcelas: as peças compradas viram contas a pagar nessas datas.@endif
        </div>

        <form method="POST" action="{{ route('commerce.supply.confirm', $batch) }}" class="stack">
            @csrf
            <div class="card form-grid" style="align-items:end;">
                <div><label for="supplier">Fornecedora</label><input id="supplier" name="supplier" value="{{ old('supplier', $batch->supplier) }}" required maxlength="120"></div>
                <div><label for="document_number">Nº do comprovante</label><input id="document_number" name="document_number" value="{{ old('document_number', $batch->document_number) }}" maxlength="40"></div>
                <div><label for="closing_on">Fechamento / acerto</label><input id="closing_on" name="closing_on" type="date" value="{{ old('closing_on', $batch->closing_on?->toDateString()) }}"></div>
                <div>
                    <label for="all-mode">Origem de todos</label>
                    <select id="all-mode"><option value="">— escolher por produto —</option>@foreach (\App\Commerce\Supply\SupplyBatch::MODES as $k => $l)<option value="{{ $k }}">{{ $k === 'compra' ? 'Tudo comprado' : 'Tudo consignado' }}</option>@endforeach</select>
                </div>
            </div>
            <div class="helper">Preços sugeridos com o preço do comprovante como <b>{{ $batch->price_basis === 'custo' ? 'custo' : 'preço de venda' }}</b> e {{ $batch->price_basis === 'custo' ? 'margem' : 'comissão' }} de {{ rtrim(rtrim(number_format($batch->percent, 2, ',', ''), '0'), ',') }}%. Ajuste o que precisar.</div>

            @foreach ($groups as $g => $group)
                <div class="card" data-group>
                    <div class="form-grid" style="align-items:end;">
                        <div style="grid-column: span 2;">
                            <label for="g{{ $g }}-name">Produto</label>
                            <input id="g{{ $g }}-name" name="groups[{{ $g }}][name]" value="{{ old("groups.$g.name", $group['product_name'] ?? $group['name']) }}" required maxlength="150">
                            <div class="helper">{{ $group['product_id'] ? '✔ Já cadastrado: só soma o estoque (e cria as cores/tamanhos novos).' : 'Novo: será cadastrado.' }}{{ $group['ref'] ? ' Ref. '.$group['ref'] : '' }}</div>
                        </div>
                        <div>
                            <label for="g{{ $g }}-mode">Origem</label>
                            <select id="g{{ $g }}-mode" name="groups[{{ $g }}][mode]" data-mode>@foreach (\App\Commerce\Supply\SupplyBatch::MODES as $k => $l)<option value="{{ $k }}" @selected(old("groups.$g.mode", $group['mode']) === $k)>{{ $k === 'compra' ? 'Comprado' : 'Consignado' }}</option>@endforeach</select>
                        </div>
                        <div>
                            <label for="g{{ $g }}-cat">Categoria</label>
                            <select id="g{{ $g }}-cat" name="groups[{{ $g }}][category_id]"><option value="">— sem categoria —</option>@foreach ($categories as $cat)<option value="{{ $cat->id }}" @selected(old("groups.$g.category_id", $group['category_id']) === $cat->id)>{{ $cat->fullName() }}</option>@endforeach</select>
                        </div>
                        <div><label for="g{{ $g }}-sale">Preço de venda (R$)</label><input id="g{{ $g }}-sale" name="groups[{{ $g }}][sale]" value="{{ old("groups.$g.sale", $input($group['sale_cents'])) }}" inputmode="decimal" required></div>
                        <div><label for="g{{ $g }}-cost">Custo (R$)</label><input id="g{{ $g }}-cost" name="groups[{{ $g }}][cost]" value="{{ old("groups.$g.cost", $input($group['cost_cents'])) }}" inputmode="decimal" required><div class="helper">no comprovante: {{ $money($group['doc_price_cents']) }}</div></div>
                    </div>
                    <div class="table-wrap" style="margin-top:12px;"><table>
                        <thead><tr><th>Como veio</th><th style="width:170px;">Cor</th><th style="width:110px;">Tamanho</th><th style="width:150px;">Cód. de barras</th><th style="width:100px;">Qtd.</th></tr></thead>
                        <tbody>
                            @foreach ($group['lines'] as $l => $line)
                                <tr>
                                    <td class="helper">{{ $line['description'] }}@if (! empty($line['known']))<div><span class="badge success">reconhecida</span></div>@endif</td>
                                    <td><input name="groups[{{ $g }}][lines][{{ $l }}][color]" value="{{ old("groups.$g.lines.$l.color", $line['color']) }}" maxlength="40" aria-label="Cor"></td>
                                    <td><input name="groups[{{ $g }}][lines][{{ $l }}][size]" value="{{ old("groups.$g.lines.$l.size", $line['size']) }}" maxlength="20" aria-label="Tamanho"></td>
                                    <td class="helper">{{ $line['barcode'] }}</td>
                                    <td><input name="groups[{{ $g }}][lines][{{ $l }}][quantity]" type="number" min="0" value="{{ old("groups.$g.lines.$l.quantity", $line['quantity']) }}" aria-label="Quantidade"></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table></div>
                    <label style="display:flex;gap:8px;align-items:center;font-weight:400;margin-top:8px;"><input type="checkbox" name="groups[{{ $g }}][skip]" value="1" style="width:auto;" @checked(old("groups.$g.skip"))> Não lançar este produto</label>
                </div>
            @endforeach

            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button type="submit">Lançar no estoque</button>
                <button type="submit" form="discard" class="secondary">Descartar leitura</button>
            </div>
        </form>
        <form id="discard" method="POST" action="{{ route('commerce.supply.destroy', $batch) }}" onsubmit="return confirm('Descartar esta leitura? Nada foi lançado ainda.');">@csrf</form>
        <script>
            document.getElementById('all-mode').addEventListener('change', function () {
                if (this.value) document.querySelectorAll('[data-mode]').forEach((s) => { s.value = this.value; });
            });
        </script>
    @else
        @php($consigned = $batch->items->where('mode', 'consignado'))
        @php($bought = $batch->items->where('mode', 'compra'))
        <div class="grid cards">
            <div class="card"><div class="muted">Peças recebidas</div><h2 style="margin:4px 0;">{{ $batch->items->sum('quantity') }}</h2><div class="helper">{{ $consigned->sum('quantity') }} consignadas · {{ $bought->sum('quantity') }} compradas</div></div>
            <div class="card"><div class="muted">Custo das peças</div><h2 style="margin:4px 0;">{{ $money($batch->items->sum(fn ($i) => $i->cost_cents * $i->quantity)) }}</h2><div class="helper">comprovante: {{ $money($batch->total_cents) }}</div></div>
            <div class="card"><div class="muted">Fechamento</div><h2 style="margin:4px 0;font-size:1.2rem;">{{ $batch->closing_on?->format('d/m/Y') ?? '—' }}</h2><div class="helper">{{ \App\Commerce\Supply\SupplyBatch::STATUSES[$batch->status] }}{{ $batch->settled_at ? ' em '.$batch->settled_at->format('d/m/Y') : '' }}</div></div>
        </div>

        @if ($batch->status === 'aberta' && $consigned->isNotEmpty())
            <form class="card" method="POST" action="{{ route('commerce.supply.settle', $batch) }}" style="margin-top:16px;" onsubmit="return confirm('Fazer o acerto? As peças a devolver saem do estoque e o vendido vira conta a pagar.');">
                @csrf
                <h3 style="margin-top:0;">Acerto da consignação</h3>
                <p class="helper" style="margin-top:0;">"Devolver" já vem com o que você tem hoje de cada peça (até o que veio nesta remessa). Ajuste se separou algo para ficar. O que não voltar conta como vendido e vira conta a pagar.</p>
                <div class="table-wrap"><table>
                    <thead><tr><th>Peça</th><th style="text-align:right;">Veio</th><th style="text-align:right;">Tem hoje</th><th style="width:110px;">Devolver</th><th style="text-align:right;">Vendido</th><th style="text-align:right;">A pagar</th></tr></thead>
                    <tbody>
                        @foreach ($consigned as $item)
                            @php($has = max(0, min($item->quantity, $onHand[$item->variant_id] ?? 0)))
                            <tr data-settle data-qty="{{ $item->quantity }}" data-cost="{{ $item->cost_cents }}">
                                <td>{{ $item->variant?->fullName() ?? $item->description }}<div class="helper">{{ $item->barcode }}</div></td>
                                <td style="text-align:right;">{{ $item->quantity }}</td>
                                <td style="text-align:right;">{{ $onHand[$item->variant_id] ?? 0 }}</td>
                                <td><input name="returns[{{ $item->id }}]" type="number" min="0" max="{{ $item->quantity }}" value="{{ $has }}" aria-label="Devolver" data-back></td>
                                <td style="text-align:right;" data-sold>{{ $item->quantity - $has }}</td>
                                <td style="text-align:right;white-space:nowrap;" data-owed>{{ $money(($item->quantity - $has) * $item->cost_cents) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot><tr><td colspan="5" style="text-align:right;"><strong>Total a pagar à {{ $batch->supplier }}</strong></td><td style="text-align:right;white-space:nowrap;"><strong id="owed-total"></strong></td></tr></tfoot>
                </table></div>
                <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-top:12px;">
                    <div><label for="due">Pagar até</label><input id="due" name="due" type="date" value="{{ ($batch->closing_on ?? today())->toDateString() }}"></div>
                    <button type="submit">Fazer o acerto</button>
                </div>
                <script>
                    (function () {
                        const money = (c) => 'R$ ' + (c / 100).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                        const sync = function () {
                            let total = 0;
                            document.querySelectorAll('[data-settle]').forEach(function (row) {
                                const qty = +row.dataset.qty, back = Math.min(qty, Math.max(0, parseInt(row.querySelector('[data-back]').value, 10) || 0));
                                row.querySelector('[data-sold]').textContent = qty - back;
                                row.querySelector('[data-owed]').textContent = money((qty - back) * row.dataset.cost);
                                total += (qty - back) * row.dataset.cost;
                            });
                            document.getElementById('owed-total').textContent = money(total);
                        };
                        document.addEventListener('input', function (e) { if (e.target.matches('[data-back]')) sync(); });
                        sync();
                    })();
                </script>
            </form>
        @endif

        <div class="card" style="margin-top:16px;">
            <h3 style="margin-top:0;">Peças da remessa</h3>
            <div class="table-wrap"><table>
                <thead><tr><th>Peça</th><th>Origem</th><th style="text-align:right;">Qtd.</th><th style="text-align:right;">Custo</th><th style="text-align:right;">Devolvido</th><th style="text-align:right;">Vendido</th></tr></thead>
                <tbody>
                    @foreach ($batch->items as $item)
                        <tr>
                            <td>@if ($item->variant)<a href="{{ route('commerce.products.edit', $item->variant->product_id) }}">{{ $item->variant->fullName() }}</a>@else{{ $item->description }}@endif<div class="helper">{{ $item->barcode }}</div></td>
                            <td><span class="badge">{{ $item->mode === 'compra' ? 'comprado' : 'consignado' }}</span></td>
                            <td style="text-align:right;">{{ $item->quantity }}</td>
                            <td style="text-align:right;white-space:nowrap;">{{ $money($item->cost_cents) }}</td>
                            <td style="text-align:right;">{{ $item->returned ?? '—' }}</td>
                            <td style="text-align:right;">{{ $item->sold ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        </div>
    @endif
</x-core::layout>
