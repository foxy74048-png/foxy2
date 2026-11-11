<x-core::layout title="Estoque" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>
    <x-slot:actions>@include('commerce.export.buttons')@include('commerce._add', ['label' => 'Importar comprovante ou nota', 'target' => 'importar'])</x-slot:actions>
    @php($money = fn ($c) => \App\Commerce\Catalog\Money::format($c))

    @include('commerce.status')
    @include('commerce.stock._tabs', ['tab' => 'remessas'])

    <form class="card stack" id="importar" method="POST" action="{{ route('commerce.supply.import') }}" enctype="multipart/form-data">
        @csrf
        <h3 style="margin:0;">Importar comprovante ou nota fiscal</h3>
        <p class="helper" style="margin:0;">Chegou mercadoria de fornecedora (consignação ou compra)? Envie o <b>PDF do comprovante</b> ou o <b>XML da nota fiscal</b> (peça à fornecedora: o XML vale para qualquer uma e já traz códigos, quantidades, custo e parcelas). Também dá para abrir o PDF, selecionar tudo, copiar e colar o texto abaixo. O sistema lê as peças, agrupa por produto (cor e tamanho) e mostra uma prévia para você conferir antes de lançar.</p>
        <div class="form-grid" style="align-items:end;">
            <div><label for="file">PDF do comprovante ou XML da nota</label><input id="file" type="file" name="file" accept="application/pdf,.pdf,.xml,text/xml,.txt"></div>
            <div>
                <label for="basis">O preço que vem no documento é</label>
                <select id="basis" name="basis">
                    <option value="auto" @selected(old('basis', 'auto') === 'auto')>Como na última remessa desta fornecedora</option>
                    <option value="venda" @selected(old('basis') === 'venda')>Preço de venda (eu ganho comissão)</option>
                    <option value="custo" @selected(old('basis') === 'custo')>Meu custo (eu coloco a margem)</option>
                </select>
            </div>
            <div><label for="percent"><span id="percent-label">Minha comissão (%)</span></label><input id="percent" name="percent" type="number" min="0" max="500" step="0.5" value="{{ old('percent') }}"></div>
        </div>
        <div class="helper">Primeira vez da fornecedora: comprovante em PDF entra como preço de venda com 30% de comissão; nota fiscal entra como custo com 100% de margem. Depois o sistema lembra o que você usou.</div>
        <details>
            <summary style="cursor:pointer;">Ou colar o texto do comprovante</summary>
            <textarea name="text" rows="6" style="margin-top:8px;" placeholder="Abra o PDF, Ctrl+A, Ctrl+C e cole aqui">{{ old('text') }}</textarea>
        </details>
        <div><button type="submit">Ler comprovante</button></div>
        <div class="helper">Na prévia você ainda escolhe, produto a produto: consignado ou comprado, categoria, preço de venda e custo. A próxima remessa da mesma fornecedora (reconhecida pelo CNPJ ou pelo nome) já vem com as peças no produto, na cor e no tamanho que você conferiu — inclusive o que você corrigiu — e só soma o estoque.</div>
        <script>
            (function () {
                const basis = document.getElementById('basis'), label = document.getElementById('percent-label');
                const percent = document.getElementById('percent');
                const sync = function () {
                    label.textContent = basis.value === 'custo' ? 'Minha margem sobre o custo (%)' : (basis.value === 'venda' ? 'Minha comissão (%)' : 'Comissão ou margem (%)');
                    percent.placeholder = basis.value === 'auto' ? 'a da última vez' : (basis.value === 'custo' ? '100' : '30');
                };
                basis.addEventListener('change', sync); sync();
            })();
        </script>
    </form>

    <div class="card" style="margin-top:16px;">
        <h3 style="margin-top:0;">Remessas</h3>
        @if ($batches->isEmpty())
            <div class="empty">Nenhuma remessa ainda.</div>
        @else
            <div class="table-wrap"><table>
                <thead><tr><th>Recebida</th><th>Fornecedora</th><th>Itens</th><th style="text-align:right;">Total do comprovante</th><th>Fechamento</th><th>Situação</th><th></th></tr></thead>
                <tbody>
                    @foreach ($batches as $b)
                        @php($late = $b->status === 'aberta' && $b->closing_on && $b->closing_on->lte(today()->addDays(7)))
                        <tr>
                            <td>{{ $b->received_on?->format('d/m/Y') }}</td>
                            <td><a href="{{ route('commerce.supply.show', $b) }}"><strong>{{ $b->label() }}</strong></a></td>
                            <td>{{ $b->items_count ?: count($b->groups ?? []).' produto(s) para conferir' }}</td>
                            <td style="text-align:right;white-space:nowrap;">{{ $money($b->total_cents) }}</td>
                            <td>{{ $b->closing_on?->format('d/m/Y') ?? '—' }}@if ($late)<div class="helper" style="color:var(--danger-text);">acerto {{ $b->closing_on->isPast() ? 'atrasado' : 'chegando' }}</div>@endif</td>
                            <td><span @class(['badge', 'success' => $b->status === 'acertada', 'danger' => $late])>{{ \App\Commerce\Supply\SupplyBatch::STATUSES[$b->status] ?? $b->status }}</span></td>
                            <td style="text-align:right;"><a class="button secondary" href="{{ route('commerce.supply.show', $b) }}">{{ $b->status === 'rascunho' ? 'Conferir' : ($b->status === 'aberta' ? 'Abrir / acerto' : 'Abrir') }}</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            <div style="margin-top:12px;">{{ $batches->links() }}</div>
        @endif
    </div>
</x-core::layout>
