<x-core::layout title="Lançamentos" product="Foxy Finanças" :subtitle="$currentWallet->name">
    <x-slot:nav>@include('finance.nav')</x-slot:nav>
    <x-slot:actions>@include('platform.export-buttons')@if ($canManage && $activeAccounts->isNotEmpty())@include('finance._add', ['label' => 'Lançamento', 'target' => 'lancar'])@endif</x-slot:actions>
    @php($money = fn ($c) => \App\Platform\Support\Money::format($c))
    @php($v = $filters->values)

    @include('finance.status')

    {{-- Mês: setas para andar de um mês para o outro --}}
    @if ($nb = $filters->neighbours())
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
            <a class="button secondary" href="{{ route('finance.transactions', array_merge($v, ['mes' => $nb[0]])) }}" aria-label="Mês anterior">‹</a>
            <h2 style="margin:0;font-size:1.2rem;">{{ \Illuminate\Support\Str::ucfirst(\App\Finance\Transactions\TransactionFilters::monthName($filters->from)) }}</h2>
            <a class="button secondary" href="{{ route('finance.transactions', array_merge($v, ['mes' => $nb[1]])) }}" aria-label="Mês seguinte">›</a>
            @if ($v['mes'] !== today()->format('Y-m'))<a class="helper" href="{{ route('finance.transactions') }}">voltar para este mês</a>@endif
        </div>
    @endif

    <div class="grid cards">
        <div class="card"><div class="muted">Entrou</div><h2 style="margin:4px 0;color:var(--success-text);">{{ $money($totals['income'] ?? 0) }}</h2></div>
        <div class="card"><div class="muted">Saiu</div><h2 style="margin:4px 0;">{{ $money($totals['expense'] ?? 0) }}</h2></div>
        @php($left = ($totals['income'] ?? 0) - ($totals['expense'] ?? 0))
        <div class="card"><div class="muted">Sobrou</div><h2 style="margin:4px 0;{{ $left < 0 ? 'color:var(--danger-text);' : '' }}">{{ $money($left) }}</h2><div class="helper">entrou menos saiu (transferências não contam)</div></div>
        <a class="card" href="{{ route('finance.transactions', array_merge($v, ['situacao' => 'previsto'])) }}"><div class="muted">Ainda previsto</div><h2 style="margin:4px 0;font-size:1.1rem;">{{ $money($totals['planned_expense'] ?? 0) }} a pagar</h2><div class="helper">{{ $money($totals['planned_income'] ?? 0) }} a receber</div></a>
    </div>

    @if ($canManage)
        @if ($activeAccounts->isEmpty())
            {{-- Primeiro passo: onde o dinheiro fica --}}
            <form class="card form-grid" id="lancar" method="POST" action="{{ route('finance.accounts.store') }}" style="margin-top:16px;align-items:end;">
                @csrf
                <input type="hidden" name="back" value="lancamentos">
                <div style="grid-column:1/-1;">
                    <h3 style="margin:0;">Antes de lançar: onde o seu dinheiro fica?</h3>
                    <p class="helper" style="margin:4px 0 0;">Cadastre a primeira conta (ex.: Nubank, Carteira, Cartão Inter). Depois dá para cadastrar as outras em <a href="{{ route('finance.accounts') }}">Contas e cartões</a>.</p>
                </div>
                <div><label for="a-kind">Tipo</label><select id="a-kind" name="kind">@foreach (\App\Finance\Accounts\Account::KINDS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                <div><label for="a-name">Nome</label><input id="a-name" name="name" required maxlength="80" placeholder="Ex.: Nubank"></div>
                <div><label for="a-open">Saldo de hoje (R$)</label><input id="a-open" name="opening_balance" inputmode="decimal" placeholder="0,00"></div>
                <div><label for="a-close">Cartão: fecha dia</label><input id="a-close" name="closing_day" type="number" min="1" max="31"></div>
                <div><label for="a-due">Cartão: vence dia</label><input id="a-due" name="due_day" type="number" min="1" max="31"></div>
                <div><button type="submit">Cadastrar e lançar</button></div>
            </form>
        @else
            {{-- Lançar em 3 toques: tipo, valor, descrição. Conta, data e categoria já vêm preenchidas. --}}
            <form class="card stack" id="lancar" method="POST" action="{{ route('finance.transactions.store') }}" style="margin-top:16px;" data-tx-form>
                @csrf
                @php($kind = old('kind', request('tipo') === 'recebimento' ? 'recebimento' : (request('tipo') === 'transferencia' ? 'transferencia' : 'gasto')))
                <div style="display:flex;gap:8px;flex-wrap:wrap;" role="radiogroup" aria-label="Tipo de lançamento">
                    @foreach (\App\Finance\Transactions\Transaction::KINDS as $k => $l)
                        <label @class(['button', 'secondary' => $kind !== $k]) data-kind-btn="{{ $k }}" style="cursor:pointer;">
                            <input type="radio" name="kind" value="{{ $k }}" @checked($kind === $k) style="position:absolute;opacity:0;width:1px;height:1px;"> {{ $l }}
                        </label>
                    @endforeach
                </div>
                <div class="form-grid" style="align-items:end;">
                    <div><label for="t-amount">Valor (R$)</label><input id="t-amount" name="amount" value="{{ old('amount') }}" required inputmode="decimal" autocomplete="off" placeholder="0,00" autofocus></div>
                    <div><label for="t-desc">Descrição</label><input id="t-desc" name="description" value="{{ old('description') }}" required maxlength="160" placeholder="Ex.: Mercado, Salário, Netflix"></div>
                    <div data-for-kind="gasto">
                        <label for="t-cat-gasto">Categoria</label>
                        <select id="t-cat-gasto" name="category_id">
                            <option value="">— sem categoria —</option>
                            @foreach ($categoryOptions['gasto'] ?? [] as $group => $items)<optgroup label="{{ $group }}">@foreach ($items as $id => $name)<option value="{{ $id }}" @selected(old('category_id') === $id)>{{ $name }}</option>@endforeach</optgroup>@endforeach
                        </select>
                    </div>
                    <div data-for-kind="recebimento">
                        <label for="t-cat-rec">Categoria</label>
                        <select id="t-cat-rec" name="category_id">
                            <option value="">— sem categoria —</option>
                            @foreach ($categoryOptions['recebimento'] ?? [] as $group => $items)<optgroup label="{{ $group }}">@foreach ($items as $id => $name)<option value="{{ $id }}" @selected(old('category_id') === $id)>{{ $name }}</option>@endforeach</optgroup>@endforeach
                        </select>
                    </div>
                    <div>
                        <label for="t-account"><span data-for-kind="gasto recebimento">Conta ou cartão</span><span data-for-kind="transferencia">Saiu de</span></label>
                        <select id="t-account" name="account_id" required>
                            @foreach ($activeAccounts as $a)<option value="{{ $a->id }}" @selected(old('account_id', $defaultAccount) === $a->id)>{{ $a->name }}{{ $a->isCard() ? ' (cartão)' : '' }}</option>@endforeach
                        </select>
                    </div>
                    <div data-for-kind="transferencia">
                        <label for="t-to">Foi para</label>
                        <select id="t-to" name="to_account_id">
                            @foreach ($activeAccounts as $a)<option value="{{ $a->id }}" @selected(old('to_account_id') === $a->id)>{{ $a->name }}{{ $a->isCard() ? ' (pagar fatura)' : '' }}</option>@endforeach
                        </select>
                    </div>
                    <div><label for="t-date">Data</label><input id="t-date" name="date" type="date" value="{{ old('date', today()->toDateString()) }}" required></div>
                    <div>
                        <label for="t-mode">Como</label>
                        <select id="t-mode" name="mode">@foreach (\App\Finance\Transactions\TransactionService::MODES as $k => $l)<option value="{{ $k }}" @selected(old('mode', 'unica') === $k)>{{ $l }}</option>@endforeach</select>
                    </div>
                    <div><label for="t-count">Quantas vezes / meses</label><input id="t-count" name="count" type="number" min="2" max="{{ \App\Finance\Transactions\TransactionService::MAX_INSTALLMENTS }}" value="{{ old('count', 12) }}"></div>
                    <div style="grid-column:1/-1;"><label for="t-notes">Observação (opcional)</label><input id="t-notes" name="notes" value="{{ old('notes') }}" maxlength="2000"></div>
                </div>
                <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                    <button type="submit">Lançar</button>
                    <span class="helper">Parcelado divide o valor (10x de uma compra de R$ 1.000 = 10 de R$ 100, uma em cada fatura). Todo mês repete o mesmo valor (aluguel, salário). Data futura fica como <b>previsto</b> até você confirmar.</span>
                </div>
            </form>
        @endif
    @endif

    {{-- Filtros: sempre visíveis --}}
    <form class="card form-grid" method="GET" action="{{ route('finance.transactions') }}" style="margin-top:16px;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));align-items:end;">
        <div><label for="f-mes">Mês</label><input id="f-mes" name="mes" type="month" value="{{ $v['mes'] ?? '' }}"></div>
        <div><label for="f-de">Ou de</label><input id="f-de" name="de" type="date" value="{{ $v['de'] ?? '' }}"></div>
        <div><label for="f-ate">até</label><input id="f-ate" name="ate" type="date" value="{{ $v['ate'] ?? '' }}"></div>
        <div><label for="f-conta">Conta</label><select id="f-conta" name="conta"><option value="">Todas</option>@foreach ($accounts as $a)<option value="{{ $a->id }}" @selected(($v['conta'] ?? '') === $a->id)>{{ $a->name }}{{ $a->archived_at ? ' (arquivada)' : '' }}</option>@endforeach</select></div>
        <div><label for="f-cat">Categoria</label><select id="f-cat" name="categoria"><option value="">Todas</option>@foreach (collect($categoryNames)->sort() as $id => $name)<option value="{{ $id }}" @selected(($v['categoria'] ?? '') === $id)>{{ $name }}</option>@endforeach</select></div>
        <div><label for="f-tipo">Tipo</label><select id="f-tipo" name="tipo"><option value="">Todos</option>@foreach (\App\Finance\Transactions\Transaction::KINDS as $k => $l)<option value="{{ $k }}" @selected(($v['tipo'] ?? '') === $k)>{{ $l }}</option>@endforeach</select></div>
        <div><label for="f-sit">Situação</label><select id="f-sit" name="situacao"><option value="">Todas</option>@foreach (\App\Finance\Transactions\Transaction::STATUSES as $k => $l)<option value="{{ $k }}" @selected(($v['situacao'] ?? '') === $k)>{{ $l }}</option>@endforeach</select></div>
        <div><label for="f-busca">Buscar</label><input id="f-busca" name="busca" value="{{ $v['busca'] ?? '' }}" placeholder="descrição"></div>
        <div style="display:flex;gap:8px;"><button type="submit">Filtrar</button><a class="button secondary" href="{{ route('finance.transactions') }}">Limpar</a></div>
    </form>

    <div class="card" style="margin-top:16px;">
        @if ($rows->isEmpty())
            <div class="empty">Nenhum lançamento {{ isset($v['mes']) && count($v) === 1 ? 'neste mês' : 'com esses filtros' }}.</div>
        @else
            <div class="table-wrap"><table>
                <thead><tr><th>Data</th><th>Descrição</th><th>Categoria</th><th>Conta</th><th style="text-align:right;">Valor</th>@if ($canManage)<th style="text-align:right;">Ações</th>@endif</tr></thead>
                <tbody>
                    @foreach ($rows as $t)
                        <tr>
                            <td style="white-space:nowrap;">{{ $t->date->format('d/m/Y') }}</td>
                            <td>
                                <strong>{{ $t->description }}</strong>
                                @if ($label = $t->seriesLabel())<span class="muted">{{ $label }}</span>@endif
                                @if ($t->isPlanned())<span class="badge">previsto</span>@endif
                                @if ($t->source !== 'manual')<span class="badge">{{ \App\Finance\Transactions\Transaction::SOURCES[$t->source] ?? $t->source }}</span>@endif
                                @if ($t->notes)<div class="helper">{{ \Illuminate\Support\Str::limit($t->notes, 80) }}</div>@endif
                            </td>
                            <td>{{ $t->isTransfer() ? 'Transferência' : ($t->category?->fullName() ?? '—') }}</td>
                            <td>{{ $t->account?->name }}@if ($t->isTransfer()) → {{ $t->toAccount?->name }}@endif @if ($t->invoice_month)<div class="helper">fatura {{ \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $t->invoice_month.'-01')->format('m/Y') }}</div>@endif</td>
                            <td style="text-align:right;white-space:nowrap;{{ $t->kind === 'recebimento' ? 'color:var(--success-text);' : '' }}">{{ $t->kind === 'gasto' ? '− ' : ($t->kind === 'recebimento' ? '+ ' : '') }}{{ $money($t->amount_cents) }}</td>
                            @if ($canManage)
                                <td style="text-align:right;white-space:nowrap;">
                                    @if ($t->isPlanned())
                                        <form method="POST" action="{{ route('finance.transactions.confirm', $t) }}" style="display:inline;">@csrf<button type="submit">{{ $t->kind === 'recebimento' ? 'Recebi' : 'Paguei' }}</button></form>
                                    @endif
                                    <a class="button secondary" href="{{ route('finance.transactions.edit', $t) }}">Editar</a>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            <div style="margin-top:12px;">{{ $rows->links() }}</div>
        @endif
    </div>

    <x-slot:scripts>
    <script>
    (function () {
        var form = document.querySelector('[data-tx-form]');
        if (!form) return;
        function sync() {
            var kind = form.querySelector('input[name=kind]:checked').value;
            form.querySelectorAll('[data-kind-btn]').forEach(function (b) { b.classList.toggle('secondary', b.getAttribute('data-kind-btn') !== kind); });
            form.querySelectorAll('[data-for-kind]').forEach(function (el) {
                var on = el.getAttribute('data-for-kind').split(' ').indexOf(kind) !== -1;
                el.style.display = on ? '' : 'none';
                el.querySelectorAll('select,input').forEach(function (f) { f.disabled = !on; });
            });
            var mode = form.querySelector('#t-mode');
            mode.querySelector('option[value=parcelas]').disabled = kind === 'transferencia';
            if (kind === 'transferencia' && mode.value === 'parcelas') mode.value = 'unica';
            syncCount();
        }
        function syncCount() {
            var mode = form.querySelector('#t-mode').value, count = form.querySelector('#t-count');
            count.closest('div').style.display = mode === 'unica' ? 'none' : '';
            count.disabled = mode === 'unica';
        }
        form.querySelectorAll('input[name=kind]').forEach(function (r) { r.addEventListener('change', sync); });
        form.querySelector('#t-mode').addEventListener('change', syncCount);
        sync();
    })();
    </script>
    </x-slot:scripts>
</x-core::layout>
