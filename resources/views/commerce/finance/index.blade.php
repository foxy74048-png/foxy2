<x-core::layout title="Financeiro" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>
    <x-slot:actions>@include('commerce.export.buttons')</x-slot:actions>
    @php($money = fn ($c) => \App\Commerce\Catalog\Money::format($c))
    @php($methods = \App\Commerce\Http\FinanceController::METHODS)

    @include('commerce.status')

    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;" role="tablist">
        @foreach (\App\Commerce\Http\FinanceController::TABS as $key => $label)
            <a role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" href="{{ route('commerce.finance', ['aba' => $key]) }}" @class(['button', 'secondary' => $tab !== $key])>{{ $label }}</a>
        @endforeach
    </div>

    @if ($tab === 'fluxo')
        <div class="grid cards">
            <div class="card"><div class="muted">Entradas previstas</div><h2 style="margin:4px 0;">{{ $money($flow['in']) }}</h2><div class="helper">contas a receber em aberto no período (e as vencidas)</div></div>
            <div class="card"><div class="muted">Saídas previstas</div><h2 style="margin:4px 0;">{{ $money($flow['out']) }}</h2><div class="helper">contas a pagar em aberto</div></div>
            <div class="card"><div class="muted">Saldo previsto</div><h2 style="margin:4px 0;color:{{ $flow['in'] - $flow['out'] < 0 ? 'var(--danger-text)' : 'inherit' }};">{{ $money($flow['in'] - $flow['out']) }}</h2></div>
        </div>
        <form class="card" method="GET" action="{{ route('commerce.finance') }}" style="margin-top:16px;display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
            <input type="hidden" name="aba" value="fluxo">
            <div><label for="semanas">Próximas</label><select id="semanas" name="semanas">@foreach ([4, 8, 13, 26] as $w)<option value="{{ $w }}" @selected((int) ($filters['semanas'] ?? 8) === $w)>{{ $w }} semanas</option>@endforeach</select></div>
            <div><button type="submit">Ver</button></div>
        </form>
        <div class="card" style="margin-top:16px;">
            <div class="table-wrap"><table>
                <thead><tr><th>Período</th><th style="text-align:right;">Entradas</th><th style="text-align:right;">Saídas</th><th style="text-align:right;">Saldo do período</th><th style="text-align:right;">Acumulado</th></tr></thead>
                <tbody>
                    @foreach ($flow['rows'] as $row)
                        <tr>
                            <td>{{ $row['label'] }}</td>
                            <td style="text-align:right;white-space:nowrap;">{{ $money($row['in']) }}</td>
                            <td style="text-align:right;white-space:nowrap;">{{ $money($row['out']) }}</td>
                            <td style="text-align:right;white-space:nowrap;{{ $row['in'] - $row['out'] < 0 ? 'color:var(--danger-text);' : '' }}">{{ $money($row['in'] - $row['out']) }}</td>
                            <td style="text-align:right;white-space:nowrap;{{ $row['balance'] < 0 ? 'color:var(--danger-text);' : '' }}"><strong>{{ $money($row['balance']) }}</strong></td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            <p class="helper" style="margin-bottom:0;">Previsão com o que está lançado em aberto. Vendas à vista não entram aqui (já estão no Painel); lance o crediário/fiado como venda a prazo e as despesas em "A pagar".</p>
        </div>
    @else
        @php($receivable = $tab === 'receber')
        <div class="grid cards">
            <a class="card" href="{{ route('commerce.finance', ['aba' => $tab]) }}"><div class="muted">Em aberto</div><h2 style="margin:4px 0;">{{ $money($summary['open']) }}</h2><div class="helper">{{ $summary['open_count'] }} conta(s)</div></a>
            <a class="card" href="{{ route('commerce.finance', ['aba' => $tab, 'situacao' => 'vencidas']) }}"><div class="muted">Vencidas</div><h2 style="margin:4px 0;color:{{ $summary['overdue'] ? 'var(--danger-text)' : 'inherit' }};">{{ $money($summary['overdue']) }}</h2><div class="helper">{{ $summary['overdue_count'] }} conta(s)</div></a>
            <a class="card" href="{{ route('commerce.finance', ['aba' => $tab, 'de' => today()->toDateString(), 'ate' => today()->addDays(7)->toDateString()]) }}"><div class="muted">Vencem em 7 dias</div><h2 style="margin:4px 0;">{{ $money($summary['week']) }}</h2></a>
            <div class="card"><div class="muted">{{ $receivable ? 'Recebido' : 'Pago' }} neste mês</div><h2 style="margin:4px 0;">{{ $money($summary['month_done']) }}</h2></div>
        </div>

        {{-- Lançar --}}
        <details class="card" style="margin-top:16px;" @if ($errors->any()) open @endif>
            <summary style="cursor:pointer;font-weight:600;">{{ $receivable ? '+ Nova conta a receber' : '+ Nova conta a pagar' }}</summary>
            @if ($receivable)
                <form method="POST" action="{{ route('commerce.finance.receivables.store') }}" class="form-grid" style="margin-top:12px;align-items:end;">
                    @csrf
                    <div>
                        <label for="r-customer">Cliente</label>
                        <select id="r-customer" name="customer_id"><option value="">— nova ou sem cadastro —</option>@foreach ($customers as $c)<option value="{{ $c->id }}" @selected(old('customer_id') === $c->id)>{{ $c->name }}{{ $c->phone ? ' · '.$c->phone : '' }}</option>@endforeach</select>
                    </div>
                    <div><label for="r-cname">Ou nova: nome</label><input id="r-cname" name="customer_name" value="{{ old('customer_name') }}" maxlength="255"></div>
                    <div><label for="r-cphone">WhatsApp</label><input id="r-cphone" name="customer_phone" value="{{ old('customer_phone') }}" maxlength="30"></div>
                    <div><label for="r-desc">Descrição</label><input id="r-desc" name="description" value="{{ old('description') }}" required maxlength="160" placeholder="Ex.: Compra de 10/11 (fiado)"></div>
                    <div><label for="r-amount">Valor total (R$)</label><input id="r-amount" name="amount" value="{{ old('amount') }}" required inputmode="decimal"></div>
                    <div><label for="r-inst">Parcelas</label><input id="r-inst" name="installments" type="number" min="1" max="{{ \App\Commerce\Finance\FinanceService::MAX_INSTALLMENTS }}" value="{{ old('installments', 1) }}"></div>
                    <div><label for="r-due">1º vencimento</label><input id="r-due" name="due_date" type="date" value="{{ old('due_date', today()->addMonth()->toDateString()) }}" required></div>
                    <div><button type="submit">Lançar</button></div>
                </form>
                <p class="helper" style="margin-bottom:0;">Venda a prazo com produtos? Lance em <a href="{{ route('commerce.orders.create') }}">Pedidos › Novo pedido</a> com "Crediário / fiado": o estoque baixa e as parcelas aparecem aqui sozinhas.</p>
            @else
                <form method="POST" action="{{ route('commerce.finance.payables.store') }}" class="form-grid" style="margin-top:12px;align-items:end;">
                    @csrf
                    <div><label for="p-desc">Descrição</label><input id="p-desc" name="description" value="{{ old('description') }}" required maxlength="160" placeholder="Ex.: Fornecedor de renda"></div>
                    <div><label for="p-sup">Fornecedor (opcional)</label><input id="p-sup" name="supplier" value="{{ old('supplier') }}" maxlength="120"></div>
                    <div><label for="p-cat">Categoria</label><select id="p-cat" name="category">@foreach (\App\Commerce\Finance\Payable::CATEGORIES as $k => $l)<option value="{{ $k }}" @selected(old('category', 'fornecedor') === $k)>{{ $l }}</option>@endforeach</select></div>
                    <div><label for="p-amount">Valor (R$)</label><input id="p-amount" name="amount" value="{{ old('amount') }}" required inputmode="decimal"></div>
                    <div><label for="p-due">Vencimento</label><input id="p-due" name="due_date" type="date" value="{{ old('due_date', today()->toDateString()) }}" required></div>
                    <div>
                        <label for="p-mode">Como</label>
                        <select id="p-mode" name="mode">
                            <option value="unica" @selected(old('mode') === 'unica')>Uma vez</option>
                            <option value="parcelas" @selected(old('mode') === 'parcelas')>Parcelado (divide o valor)</option>
                            <option value="mensal" @selected(old('mode') === 'mensal')>Todo mês (mesmo valor)</option>
                        </select>
                    </div>
                    <div><label for="p-count">Quantas vezes / meses</label><input id="p-count" name="count" type="number" min="2" max="36" value="{{ old('count', 3) }}"></div>
                    <div><button type="submit">Lançar</button></div>
                </form>
            @endif
        </details>

        <form class="card form-grid" method="GET" action="{{ route('commerce.finance') }}" style="margin-top:16px;grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); align-items:end;">
            <input type="hidden" name="aba" value="{{ $tab }}">
            <div><label for="situacao">Situação</label><select id="situacao" name="situacao">@foreach (\App\Commerce\Http\FinanceController::SITUATIONS as $k => $l)<option value="{{ $k }}" @selected(($filters['situacao'] ?? 'aberto') === $k)>{{ $k === 'recebido' ? ($receivable ? 'Recebidas' : 'Pagas') : $l }}</option>@endforeach</select></div>
            <div><label for="de">Vencimento de</label><input id="de" name="de" type="date" value="{{ $filters['de'] ?? '' }}"></div>
            <div><label for="ate">até</label><input id="ate" name="ate" type="date" value="{{ $filters['ate'] ?? '' }}"></div>
            @unless ($receivable)
                <div><label for="categoria">Categoria</label><select id="categoria" name="categoria"><option value="">Todas</option>@foreach (\App\Commerce\Finance\Payable::CATEGORIES as $k => $l)<option value="{{ $k }}" @selected(($filters['categoria'] ?? '') === $k)>{{ $l }}</option>@endforeach</select></div>
            @endunless
            <div><label for="busca">{{ $receivable ? 'Cliente, telefone, descrição ou #pedido' : 'Descrição ou fornecedor' }}</label><input id="busca" name="busca" value="{{ $filters['busca'] ?? '' }}"></div>
            <div style="display:flex;gap:8px;"><button type="submit">Filtrar</button><a class="button secondary" href="{{ route('commerce.finance', ['aba' => $tab]) }}">Limpar</a></div>
        </form>

        <div class="card" style="margin-top:16px;">
            @if ($rows->isEmpty())
                <div class="empty">Nenhuma conta com esses filtros.</div>
            @else
                <div class="table-wrap"><table>
                    <thead><tr><th>Vencimento</th>@if ($receivable)<th>Cliente</th>@endif<th>Descrição</th><th style="text-align:right;">Valor</th><th>Situação</th><th style="text-align:right;">Ações</th></tr></thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td style="white-space:nowrap;">{{ $row->due_date->format('d/m/Y') }}@if ($row->isOverdue())<div class="helper" style="color:var(--danger-text);">{{ (int) $row->due_date->diffInDays(today()) }} dia(s) de atraso</div>@endif</td>
                                @if ($receivable)
                                    <td>@if ($row->customer)<a href="{{ route('commerce.customers.show', $row->customer) }}">{{ $row->customer->name }}</a><div class="helper">{{ $row->customer->phone }}</div>@else — @endif</td>
                                    <td><a href="{{ route('commerce.finance.receivables.show', $row) }}">{{ $row->description }}</a> <span class="muted">{{ $row->installmentLabel() }}</span>@if (! empty($row->reminders))<div class="helper">lembretes: {{ count($row->reminders) }}</div>@endif</td>
                                @else
                                    <td><strong>{{ $row->description }}</strong> <span class="muted">{{ $row->installmentLabel() }}{{ $row->series_id && ! $row->installments ? ' · todo mês' : '' }}</span><div class="helper">{{ \App\Commerce\Finance\Payable::CATEGORIES[$row->category] ?? $row->category }}{{ $row->supplier ? ' · '.$row->supplier : '' }}</div></td>
                                @endif
                                <td style="text-align:right;white-space:nowrap;">{{ $money($row->amount_cents) }}@if ($row->status !== 'aberto' && ($row->received_cents ?? $row->paid_cents))<div class="helper">{{ $receivable ? 'recebido' : 'pago' }} {{ ($row->received_at ?? $row->paid_at)?->format('d/m') }}</div>@endif</td>
                                <td><span @class(['badge', 'success' => in_array($row->status, ['recebido', 'pago'], true), 'danger' => $row->isOverdue()])>{{ $row->statusLabel() }}</span></td>
                                <td style="text-align:right;white-space:nowrap;">
                                    @if ($row->isOpen())
                                        <form method="POST" action="{{ $receivable ? route('commerce.finance.receivables.receive', $row) : route('commerce.finance.payables.pay', $row) }}" style="display:inline;">@csrf<button type="submit">{{ $receivable ? 'Recebi' : 'Paguei' }}</button></form>
                                        @if ($receivable && $row->customer?->phone)<form method="POST" action="{{ route('commerce.finance.receivables.charge', $row) }}" style="display:inline;">@csrf<button type="submit" class="secondary">Cobrar</button></form>@endif
                                    @endif
                                    @if ($receivable)
                                        <a class="button secondary" href="{{ route('commerce.finance.receivables.show', $row) }}">Abrir</a>
                                    @else
                                        <details style="display:inline-block;text-align:left;">
                                            <summary class="button secondary" style="list-style:none;cursor:pointer;">Mais</summary>
                                            <div class="card" style="position:absolute;right:24px;z-index:5;min-width:320px;margin-top:6px;box-shadow:var(--shadow);">
                                                @if ($row->isOpen())
                                                    <form method="POST" action="{{ route('commerce.finance.payables.update', $row) }}" class="stack">
                                                        @csrf
                                                        <label>Descrição<input name="description" value="{{ $row->description }}" maxlength="160" required></label>
                                                        <label>Fornecedor<input name="supplier" value="{{ $row->supplier }}" maxlength="120"></label>
                                                        <label>Categoria<select name="category">@foreach (\App\Commerce\Finance\Payable::CATEGORIES as $k => $l)<option value="{{ $k }}" @selected($row->category === $k)>{{ $l }}</option>@endforeach</select></label>
                                                        <label>Valor (R$)<input name="amount" value="{{ \App\Commerce\Catalog\Money::input($row->amount_cents) }}" inputmode="decimal" required></label>
                                                        <label>Vencimento<input name="due_date" type="date" value="{{ $row->due_date->toDateString() }}" required></label>
                                                        <button type="submit">Salvar</button>
                                                    </form>
                                                    <form method="POST" action="{{ route('commerce.finance.payables.pay', $row) }}" class="stack" style="margin-top:12px;">
                                                        @csrf
                                                        <strong>Pagar com outro valor/data</strong>
                                                        <label>Valor pago (R$)<input name="amount" value="{{ \App\Commerce\Catalog\Money::input($row->amount_cents) }}" inputmode="decimal"></label>
                                                        <label>Data<input name="date" type="date" value="{{ today()->toDateString() }}"></label>
                                                        <label>Forma<select name="method"><option value="">—</option>@foreach ($methods as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
                                                        <button type="submit" class="secondary">Registrar pagamento</button>
                                                    </form>
                                                    <form method="POST" action="{{ route('commerce.finance.payables.cancel', $row) }}" style="margin-top:12px;" onsubmit="return confirm('Cancelar esta conta?');">
                                                        @csrf
                                                        @if ($row->series_id)<label style="display:flex;gap:6px;align-items:center;font-weight:400;"><input type="checkbox" name="future" value="1" style="width:auto;"> e as próximas desta série</label>@endif
                                                        <button type="submit" class="danger">Cancelar conta</button>
                                                    </form>
                                                @elseif ($row->status === 'pago')
                                                    <form method="POST" action="{{ route('commerce.finance.payables.reopen', $row) }}">@csrf<button type="submit" class="secondary">Desfazer pagamento</button></form>
                                                @else
                                                    <div class="helper">Conta cancelada.</div>
                                                @endif
                                            </div>
                                        </details>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
                <div style="margin-top:12px;">{{ $rows->links() }}</div>
            @endif
        </div>
        @if ($receivable)
            <p class="helper">"Recebi" dá baixa no valor todo, hoje. Recebeu só uma parte, em outra data ou quer escolher a forma? Use <b>Abrir</b>. "Cobrar" manda a mensagem pelo <a href="{{ route('commerce.whatsapp') }}">WhatsApp da loja</a> (ou abre o seu WhatsApp com o texto pronto, se a loja não estiver conectada).</p>
        @endif
    @endif
</x-core::layout>
