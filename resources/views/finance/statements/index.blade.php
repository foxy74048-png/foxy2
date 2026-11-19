<x-core::layout title="Importar extrato" product="Foxy Finanças" :subtitle="$currentWallet->name">
    <x-slot:nav>@include('finance.nav')</x-slot:nav>
    <x-slot:actions>@if ($activeAccounts->isNotEmpty())@include('finance._add', ['label' => 'Importar arquivo', 'target' => 'importar'])@endif</x-slot:actions>
    @php($money = fn ($c) => \App\Platform\Support\Money::format($c))
    @php($signed = fn ($c) => ($c < 0 ? '− ' : '+ ').\App\Platform\Support\Money::format(abs($c)))

    @include('finance.status')

    @if ($activeAccounts->isEmpty())
        <div class="alert info">Antes de importar, cadastre a conta ou o cartão do extrato em <a href="{{ route('finance.accounts') }}#nova-conta">Contas e cartões</a>.</div>
    @else
        {{-- Enviar o arquivo: sempre aberto --}}
        <form class="card form-grid" id="importar" method="POST" action="{{ route('finance.statements.upload') }}" enctype="multipart/form-data" style="align-items:end;">
            @csrf
            <div style="grid-column:1/-1;">
                <h3 style="margin:0;">Importar extrato, fatura ou planilha</h3>
                <p class="helper" style="margin:4px 0 0;">Baixe no app ou site do banco (em "Extrato" ou "Fatura" › exportar). <b>OFX</b> é o melhor: entra direto. Planilha (.xlsx, .csv) e PDF também servem — você mostra as colunas uma vez e a conta lembra. Também serve para trazer a planilha antiga em que você controlava os gastos. Nada é lançado sem você revisar.</p>
            </div>
            <div>
                <label for="s-account">De qual conta ou cartão</label>
                <select id="s-account" name="account_id" required>@foreach ($activeAccounts as $a)<option value="{{ $a->id }}" @selected(old('account_id', $filterAccount) === $a->id)>{{ $a->name }}{{ $a->isCard() ? ' (cartão)' : '' }}</option>@endforeach</select>
            </div>
            <div><label for="s-file">Arquivo</label><input id="s-file" name="file" type="file" required accept=".ofx,.qfx,.csv,.txt,.xlsx,.pdf"></div>
            <div><button type="submit">Enviar</button></div>
        </form>
    @endif

    @if ($recentImports->isNotEmpty())
        <div class="card" style="margin-top:16px;">
            <h3 style="margin-top:0;">Importações recentes</h3>
            <div class="table-wrap"><table>
                <thead><tr><th>Quando</th><th>Conta</th><th>Arquivo</th><th style="text-align:right;">Novas</th><th style="text-align:right;">Para revisar</th><th style="text-align:right;"></th></tr></thead>
                <tbody>
                    @foreach ($recentImports as $imp)
                        <tr @if ($imp->undone_at) style="opacity:.55;" @endif>
                            <td style="white-space:nowrap;">{{ $imp->created_at->timezone('America/Sao_Paulo')->format('d/m H:i') }}</td>
                            <td>{{ $imp->account?->name }}</td>
                            <td>{{ \Illuminate\Support\Str::limit($imp->file_name, 40) }} <span class="muted">{{ \App\Finance\Statements\StatementImport::FORMATS[$imp->format] ?? $imp->format }}</span></td>
                            <td style="text-align:right;">{{ $imp->new_lines_count }} <span class="muted">de {{ $imp->lines_count }}</span></td>
                            <td style="text-align:right;">{{ $imp->pending_count }}</td>
                            <td style="text-align:right;white-space:nowrap;">
                                @if ($imp->undone_at)
                                    <span class="helper">desfeita em {{ $imp->undone_at->timezone('America/Sao_Paulo')->format('d/m H:i') }}</span>
                                @else
                                    <form method="POST" action="{{ route('finance.statements.undo', $imp) }}" style="display:inline;" onsubmit="return confirm('Apagar as linhas desta importação que ainda estão para revisar? O que já foi lançado continua.');">@csrf<button type="submit" class="secondary">Desfazer</button></form>
                                    <form method="POST" action="{{ route('finance.statements.undo', $imp) }}" style="display:inline;" onsubmit="return confirm('Desfazer TUDO desta importação, inclusive o que já foi lançado por ela?');">@csrf<input type="hidden" name="tudo" value="1"><button type="submit" class="danger">Desfazer tudo</button></form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        </div>
    @endif

    {{-- Abas e filtro --}}
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:16px 0;" role="tablist">
        @foreach (['revisar' => 'Para revisar ('.$pendingCount.')', 'resolvidas' => 'Já resolvidas', 'escondidas' => 'Fora da lista ('.$hiddenCount.')'] as $key => $label)
            <a role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" href="{{ route('finance.statements', array_filter(['aba' => $key === 'revisar' ? null : $key, 'conta' => $filterAccount])) }}" @class(['button', 'secondary' => $tab !== $key])>{{ $label }}</a>
        @endforeach
        <form method="GET" action="{{ route('finance.statements') }}" style="display:flex;gap:8px;align-items:center;margin-left:auto;">
            @if ($tab !== 'revisar')<input type="hidden" name="aba" value="{{ $tab }}">@endif
            <select name="conta" aria-label="Conta" onchange="this.form.submit()"><option value="">Todas as contas</option>@foreach ($accounts as $a)<option value="{{ $a->id }}" @selected($filterAccount === $a->id)>{{ $a->name }}</option>@endforeach</select>
        </form>
    </div>

    @if ($tab === 'revisar')
        @if ($pendingCount > 0)
            <div class="card" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end;">
                @if ($withCategoryCount > 0)
                    <form method="POST" action="{{ route('finance.statements.bulk') }}">@csrf<input type="hidden" name="scope" value="with_category"><button type="submit">Lançar as {{ $withCategoryCount }} que já têm categoria</button></form>
                @endif
                <form method="POST" action="{{ route('finance.statements.bulk') }}" id="bulk" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;">
                    @csrf<input type="hidden" name="scope" value="selected">
                    <div>
                        <label for="bulk-cat">Lançar as marcadas com a categoria</label>
                        <select id="bulk-cat" name="category_id"><option value="">a sugerida de cada uma</option>
                            @foreach ($categoryOptions as $kind => $groups)<optgroup label="— {{ \App\Finance\Categories\Category::KINDS[$kind] }}s —"></optgroup>@foreach ($groups as $group => $items)<optgroup label="{{ $group }}">@foreach ($items as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</optgroup>@endforeach @endforeach
                        </select>
                    </div>
                    <button type="submit" class="secondary">Lançar marcadas</button>
                </form>
                <p class="helper" style="margin:0;flex-basis:100%;">O que parece já estar lançado (aviso "Já está lançado?") nunca entra no lote: confira e concilie uma a uma.</p>
            </div>
        @endif

        <div class="card" style="margin-top:16px;">
            @if ($lines->isEmpty())
                <div class="empty">Nada para revisar{{ $filterAccount ? ' nesta conta' : '' }}. Envie um extrato acima.</div>
            @else
                <div class="table-wrap"><table>
                    <thead><tr><th><input type="checkbox" aria-label="Marcar todas" onclick="document.querySelectorAll('[data-line-check]').forEach(c => c.checked = this.checked)" style="width:auto;"></th><th>Data</th><th>Descrição</th><th style="text-align:right;">Valor</th><th>O que fazer</th></tr></thead>
                    <tbody>
                        @foreach ($lines as $line)
                            @php($match = $matches[$line->id] ?? null)
                            <tr>
                                <td><input type="checkbox" name="line_ids[]" value="{{ $line->id }}" form="bulk" data-line-check style="width:auto;" aria-label="Marcar"></td>
                                <td style="white-space:nowrap;">{{ $line->date->format('d/m/Y') }}@if ($line->time)<div class="helper">{{ substr($line->time, 0, 5) }}</div>@endif</td>
                                <td>
                                    <strong>{{ $line->description }}</strong>
                                    <div class="helper">{{ $line->account?->name }}</div>
                                    @if ($dup = $duplicates[$line->id] ?? null)<div class="helper" style="color:var(--warning);">Possível repetido: já existe “{{ \Illuminate\Support\Str::limit($dup->description, 50) }}” neste dia com o mesmo valor.</div>@endif
                                </td>
                                <td style="text-align:right;white-space:nowrap;{{ $line->isIncome() ? 'color:var(--success-text);' : '' }}">{{ $signed($line->amount_cents) }}</td>
                                <td style="min-width:320px;">
                                    @if ($match)
                                        <div class="alert info" style="margin-bottom:8px;padding:8px 10px;">
                                            <strong>Já está lançado?</strong> “{{ $match->description }}” em {{ $match->date->format('d/m') }} ({{ $match->account?->name }}){{ $match->isPlanned() ? ', previsto' : '' }}.
                                            <form method="POST" action="{{ route('finance.statements.match', $line) }}" style="display:inline;">@csrf<input type="hidden" name="transaction_id" value="{{ $match->id }}"><button type="submit" style="margin-left:6px;">É o mesmo — conciliar</button></form>
                                        </div>
                                    @endif
                                    <form method="POST" action="{{ route('finance.statements.create', $line) }}" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                                        @csrf
                                        <select name="category_id" aria-label="Categoria" style="max-width:220px;">
                                            <option value="">— sem categoria —</option>
                                            @foreach ($categoryOptions[$line->kind()] ?? [] as $group => $items)<optgroup label="{{ $group }}">@foreach ($items as $id => $name)<option value="{{ $id }}" @selected($line->suggested_category_id === $id)>{{ $name }}</option>@endforeach</optgroup>@endforeach
                                        </select>
                                        <button type="submit" @class(['secondary' => (bool) $match])>Lançar</button>
                                    </form>
                                    {{-- As outras saídas ficam à vista (docs: opção útil não fica escondida) --}}
                                    <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-top:6px;">
                                        @if ($activeAccounts->count() > 1)
                                            <form method="POST" action="{{ route('finance.statements.create', $line) }}" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                                                @csrf
                                                <select name="to_account_id" required aria-label="{{ $line->isIncome() ? 'Veio da conta' : 'Foi para a conta' }}" style="max-width:170px;">@foreach ($activeAccounts->where('id', '!=', $line->account_id) as $a)<option value="{{ $a->id }}">{{ $line->isIncome() ? 'de' : 'para' }} {{ $a->name }}{{ $a->isCard() ? ' (fatura)' : '' }}</option>@endforeach</select>
                                                <button type="submit" class="secondary">É transferência</button>
                                            </form>
                                        @endif
                                        <form method="POST" action="{{ route('finance.statements.ignore', $line) }}">@csrf<button type="submit" class="secondary" title="Não é gasto nem recebimento seu (ex.: estorno que se anula, dinheiro de outra pessoa)">Ignorar</button></form>
                                    <details style="flex-basis:auto;" @if ($errors->has('parts') && old('_line') === $line->id) open @endif>
                                        <summary class="button secondary" style="list-style:none;cursor:pointer;display:inline-block;">Dividir em partes</summary>
                                        <form method="POST" action="{{ route('finance.statements.split', $line) }}" class="stack" style="margin-top:8px;">
                                            @csrf<input type="hidden" name="_line" value="{{ $line->id }}">
                                            <span class="helper">Ex.: mercado + farmácia na mesma compra. A soma tem que fechar em {{ $money(abs($line->amount_cents)) }}.</span>
                                            @for ($p = 0; $p < 3; $p++)
                                                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                                    <input name="parts[{{ $p }}][amount]" inputmode="decimal" placeholder="R$" style="max-width:100px;" aria-label="Valor da parte {{ $p + 1 }}">
                                                    <select name="parts[{{ $p }}][category_id]" aria-label="Categoria da parte {{ $p + 1 }}" style="max-width:200px;"><option value="">— categoria —</option>@foreach ($categoryOptions[$line->kind()] ?? [] as $group => $items)<optgroup label="{{ $group }}">@foreach ($items as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</optgroup>@endforeach</select>
                                                    <input name="parts[{{ $p }}][description]" placeholder="descrição (opcional)" maxlength="160" style="max-width:180px;">
                                                </div>
                                            @endfor
                                            <div><button type="submit">Dividir</button></div>
                                        </form>
                                    </details>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
                <div style="margin-top:12px;">{{ $lines->links() }}</div>
            @endif
        </div>
    @else
        <div class="card">
            @if ($lines->isEmpty())
                <div class="empty">{{ $tab === 'escondidas' ? 'Nenhuma linha tirada da lista.' : 'Nada resolvido ainda.' }}</div>
            @else
                @if ($tab === 'resolvidas')
                    <form method="POST" action="{{ route('finance.statements.clear') }}" id="clear" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
                        @csrf
                        <button type="submit" name="scope" value="selected" class="secondary">Tirar da lista as marcadas</button>
                        <button type="submit" name="scope" value="ignored" class="secondary">Tirar da lista todas as ignoradas</button>
                    </form>
                @endif
                <div class="table-wrap"><table>
                    <thead><tr>@if ($tab === 'resolvidas')<th></th>@endif<th>Data</th><th>Descrição</th><th style="text-align:right;">Valor</th><th>Situação</th><th style="text-align:right;"></th></tr></thead>
                    <tbody>
                        @foreach ($lines as $line)
                            <tr>
                                @if ($tab === 'resolvidas')<td><input type="checkbox" name="line_ids[]" value="{{ $line->id }}" form="clear" style="width:auto;" aria-label="Marcar"></td>@endif
                                <td style="white-space:nowrap;">{{ $line->date->format('d/m/Y') }}</td>
                                <td>{{ $line->description }}<div class="helper">{{ $line->account?->name }}</div></td>
                                <td style="text-align:right;white-space:nowrap;{{ $line->isIncome() ? 'color:var(--success-text);' : '' }}">{{ $signed($line->amount_cents) }}</td>
                                <td>
                                    <span @class(['badge', 'success' => in_array($line->status, ['created', 'matched', 'split'], true)])>{{ \App\Finance\Statements\StatementLine::STATUSES[$line->status] ?? $line->status }}</span>
                                    @if ($line->transaction)<div class="helper">{{ $line->transaction->category?->fullName() ?? ($line->transaction->isTransfer() ? 'transferência' : 'sem categoria') }}</div>@endif
                                    @if ($line->status === 'split')<div class="helper">{{ count($line->split_details ?? []) }} partes</div>@endif
                                </td>
                                <td style="text-align:right;white-space:nowrap;">
                                    @if ($tab === 'escondidas')
                                        <form method="POST" action="{{ route('finance.statements.unhide', $line) }}">@csrf<button type="submit" class="secondary">Trazer de volta</button></form>
                                    @elseif (! $line->isPending())
                                        <form method="POST" action="{{ route('finance.statements.undo-line', $line) }}" onsubmit="return confirm('Voltar a linha para revisar? O que foi lançado por ela é apagado.');">@csrf<button type="submit" class="secondary">Desfazer</button></form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
                <div style="margin-top:12px;">{{ $lines->links() }}</div>
            @endif
        </div>
    @endif
</x-core::layout>
