<x-core::layout :title="'Sócios'.($currentWallet->is_mei ? ' e MEI' : '')" product="Foxy Finanças" :subtitle="$currentWallet->name">
    <x-slot:nav>@include('finance.nav')</x-slot:nav>
    <x-slot:actions>@include('platform.export-buttons')@if ($canManage && $partners->whereNull('archived_at')->isNotEmpty())@include('finance._add', ['label' => 'Pró-labore / retirada', 'target' => 'movimento'])@endif</x-slot:actions>
    @php($money = fn ($c) => \App\Platform\Support\Money::format($c))
    @php($input = fn ($c) => \App\Platform\Support\Money::input($c))
    @php($s = $summary)
    @php($active = $partners->whereNull('archived_at'))

    @include('finance.status')

    {{-- Período: mês (setas) ou o ano inteiro --}}
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
        @if ($period['kind'] === 'mes')
            <a class="button secondary" href="{{ route('finance.partners', ['mes' => $period['month']->copy()->subMonthNoOverflow()->format('Y-m')]) }}" aria-label="Mês anterior">‹</a>
            <h2 style="margin:0;font-size:1.2rem;">{{ \Illuminate\Support\Str::ucfirst(\App\Finance\Transactions\TransactionFilters::monthName($period['month'])) }}</h2>
            <a class="button secondary" href="{{ route('finance.partners', ['mes' => $period['month']->copy()->addMonthNoOverflow()->format('Y-m')]) }}" aria-label="Mês seguinte">›</a>
            <a class="helper" href="{{ route('finance.partners', ['ano' => $period['month']->year]) }}">ver o ano todo</a>
        @else
            <h2 style="margin:0;font-size:1.2rem;">{{ $period['label'] }}</h2>
            <a class="helper" href="{{ route('finance.partners') }}">ver por mês</a>
        @endif
    </div>

    @if ($active->isEmpty())
        <div class="alert info" style="margin-bottom:16px;">Cadastre os sócios e a parte de cada um (ex.: 50% e 50%) para ver o lucro de cada um e o acerto. MEI sozinho? Cadastre só você com 100%.</div>
    @elseif ($s['shares_total'] !== 10000)
        <div class="alert error" style="margin-bottom:16px;">As partes dos sócios somam {{ rtrim(rtrim(number_format($s['shares_total'] / 100, 2, ',', '.'), '0'), ',') }}%. O certo é 100%: ajuste abaixo.</div>
    @endif

    <div class="grid cards">
        <div class="card"><div class="muted">Entrou no negócio</div><h2 style="margin:4px 0;color:var(--success-text);">{{ $money($s['income']) }}</h2><div class="helper">sem aportes de sócio</div></div>
        <div class="card"><div class="muted">Saiu (gastos do negócio)</div><h2 style="margin:4px 0;">{{ $money($s['expense']) }}</h2><div class="helper">sem pró-labore e retiradas</div></div>
        <div class="card"><div class="muted">Lucro</div><h2 style="margin:4px 0;{{ $s['profit'] < 0 ? 'color:var(--danger-text);' : '' }}">{{ $money($s['profit']) }}</h2><div class="helper">entrou − saiu, antes de dividir</div></div>
    </div>

    @if ($s['partners']->isNotEmpty())
        <div class="card" style="margin-top:16px;">
            <h3 style="margin-top:0;">Quanto cabe a cada um</h3>
            <div class="table-wrap"><table>
                <thead><tr><th>Sócio</th><th class="num">Parte</th><th class="num">Cabe a ele</th><th class="num">Já tirou</th><th>Acerto</th></tr></thead>
                <tbody>
                    @foreach ($s['partners'] as $r)
                        <tr>
                            <td><strong>{{ $r['partner']->name }}</strong></td>
                            <td class="num">{{ $r['partner']->shareLabel() }}</td>
                            <td class="num">{{ $money($r['due']) }}</td>
                            <td class="num">{{ $money($r['took']) }}
                                <div class="helper">{{ collect([
                                    $r['pro_labore'] ? 'pró-labore '.$money($r['pro_labore']) : null,
                                    $r['retirada'] ? 'retiradas '.$money($r['retirada']) : null,
                                    $r['distribuicao'] ? 'distribuição '.$money($r['distribuicao']) : null,
                                    $r['aporte'] ? 'aportou '.$money($r['aporte']) : null,
                                    $r['settled'] ? 'acertos '.($r['settled'] > 0 ? '+' : '−').$money(abs($r['settled'])) : null,
                                ])->filter()->join(' · ') }}</div>
                            </td>
                            <td>
                                @if ($r['diff'] > 0)<span style="color:var(--danger-text);">tirou {{ $money($r['diff']) }} a mais</span>
                                @elseif ($r['diff'] < 0)<span style="color:var(--success-text);">ainda tem {{ $money(-$r['diff']) }} para tirar</span>
                                @else <span class="muted">em dia</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            @if ($s['profit'] < 0)<p class="helper" style="margin-bottom:0;">Com prejuízo no período, a parte de cada um é zero: tudo o que foi tirado aparece como "a mais".</p>@endif

            @if ($suggested && $canManage)
                <h3>Como acertar</h3>
                @foreach ($suggested as $sug)
                    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding:8px 0;border-bottom:1px solid var(--border);">
                        <span style="flex:1;min-width:240px;"><strong>{{ $sug['from']->name }}</strong> tirou {{ $money($sug['amount']) }} a mais que a parte dele{{ $active->count() > 1 ? ' — '.$sug['to']->name.' tirou a menos' : '' }}.</span>
                        <form method="POST" action="{{ route('finance.partners.settle') }}">@csrf
                            <input type="hidden" name="from_partner_id" value="{{ $sug['from']->id }}"><input type="hidden" name="to_partner_id" value="{{ $sug['to']->id }}">
                            <input type="hidden" name="amount" value="{{ $input($sug['amount']) }}"><input type="hidden" name="date" value="{{ today()->toDateString() }}">
                            <button type="submit" class="secondary">{{ $sug['from']->name }} pagou {{ $money($sug['amount']) }} a {{ $sug['to']->name }}</button>
                        </form>
                        <span class="helper">ou devolver ao caixa: registre um aporte abaixo</span>
                    </div>
                @endforeach
            @endif
        </div>
    @endif

    @if ($canManage && $active->isNotEmpty())
        <form class="card form-grid" id="movimento" method="POST" action="{{ route('finance.partners.movement') }}" style="margin-top:16px;align-items:end;">
            @csrf
            <div style="grid-column:1/-1;"><h3 style="margin:0;">Pró-labore, retirada, distribuição ou aporte</h3><p class="helper" style="margin:4px 0 0;">Sai (ou entra) na conta do negócio, mas não conta no lucro: é dinheiro do sócio.</p></div>
            <div><label for="m-partner">Sócio</label><select id="m-partner" name="partner_id">@foreach ($active as $p)<option value="{{ $p->id }}" @selected($myPartner?->id === $p->id)>{{ $p->name }}</option>@endforeach</select></div>
            <div><label for="m-kind">O quê</label><select id="m-kind" name="kind">@foreach (\App\Finance\Partners\Partner::KINDS as $k => [$label])<option value="{{ $k }}">{{ $label }}</option>@endforeach</select></div>
            <div><label for="m-amount">Valor (R$)</label><input id="m-amount" name="amount" inputmode="decimal" required></div>
            <div><label for="m-date">Data</label><input id="m-date" name="date" type="date" value="{{ today()->toDateString() }}" required></div>
            <div><label for="m-account">Conta do negócio</label><select id="m-account" name="account_id" required>@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></div>
            <div><label for="m-desc">Observação</label><input id="m-desc" name="description" maxlength="120"></div>
            <div><button type="submit">Registrar</button></div>
        </form>
    @endif

    @if ($myPartner)
        {{-- Só o próprio sócio decide: as retiradas dele entram também na carteira pessoal --}}
        <form class="card" method="POST" action="{{ route('finance.partners.mirror', $myPartner) }}" style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
            @csrf
            <div style="flex-basis:100%;"><h3 style="margin:0;">Minhas retiradas na minha carteira pessoal</h3><p class="helper" style="margin:4px 0 0;">Pró-labore, retirada e distribuição suas entram sozinhas como recebimento na conta que você escolher, sem lançar duas vezes. Só você vê e muda isto.</p></div>
            @if ($mirrorOptions->isEmpty())
                <div class="helper">Você ainda não tem uma carteira pessoal com conta cadastrada. <a href="{{ route('finance.wallets.new') }}">Criar a carteira pessoal</a>.</div>
            @else
                <div>
                    <label for="mirror">Entrar em</label>
                    <select id="mirror" name="mirror_account_id">
                        <option value="">— não lançar na carteira pessoal —</option>
                        @foreach ($mirrorOptions as $o)<optgroup label="{{ $o['wallet']->name }}">@foreach ($o['accounts'] as $a)<option value="{{ $a->id }}" @selected($myPartner->mirror_account_id === $a->id)>{{ $a->name }}</option>@endforeach</optgroup>@endforeach
                    </select>
                </div>
                <div><button type="submit" class="secondary">Salvar</button></div>
            @endif
        </form>
    @endif

    @if ($mei)
        <div class="card" style="margin-top:16px;">
            <h3 style="margin-top:0;">MEI em {{ $period['kind'] === 'ano' ? $period['value'] : $period['month']->year }}</h3>
            <div style="display:flex;justify-content:space-between;gap:8px;"><span>Faturou <strong>{{ $money($mei['total']) }}</strong></span><span class="muted">limite {{ $money($mei['limit']) }} por ano</span></div>
            <div style="height:10px;border-radius:5px;background:var(--border);margin-top:6px;"><div style="height:10px;border-radius:5px;background:{{ $mei['pct'] >= 0.9 ? 'var(--danger)' : ($mei['pct'] >= 0.7 ? 'var(--warning)' : 'var(--primary)') }};width:{{ max(2, min(100, round($mei['pct'] * 100))) }}%;"></div></div>
            <div class="helper">{{ round($mei['pct'] * 100) }}% do limite · faltam {{ $money($mei['left']) }}. Perto do limite, converse com o contador sobre virar ME.</div>
            @if ($canManage && ! $hasDas)
                <form method="POST" action="{{ route('finance.partners.das') }}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-top:12px;">
                    @csrf
                    <div><label for="das-amount">Valor do DAS (R$)</label><input id="das-amount" name="amount" inputmode="decimal" required placeholder="Ex.: 81,05" style="max-width:120px;"></div>
                    <div><label for="das-account">Pago pela conta</label><select id="das-account" name="account_id">@foreach ($accounts->reject->isCard() as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></div>
                    <div><button type="submit" class="secondary">Criar o lembrete do DAS (todo dia {{ $currentWallet->das_day ?: 20 }})</button></div>
                </form>
            @endif
        </div>
    @endif

    @if ($declaration)
        {{-- Declaração anual do MEI (DASN-SIMEI) --}}
        <div class="card" style="margin-top:16px;" id="declaracao">
            <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center;">
                <h3 style="margin:0;">Declaração anual do MEI · {{ $declaration['year'] }}</h3>
                <form method="GET" action="{{ route('finance.partners') }}#declaracao" style="display:flex;gap:6px;align-items:center;">
                    <label for="declaracao" style="margin:0;">Ano</label>
                    <select id="declaracao" name="declaracao" onchange="this.form.submit()" style="width:auto;">
                        @for ($y = today()->year; $y >= today()->year - 4; $y--)<option value="{{ $y }}" @selected($y === $declaration['year'])>{{ $y }}</option>@endfor
                    </select>
                </form>
            </div>
            <p class="helper" style="margin:6px 0 10px;">A declaração (DASN-SIMEI) de {{ $declaration['year'] }} vai até <strong>{{ $declaration['due']->format('d/m/Y') }}</strong> no Portal do Empreendedor. O valor abaixo é tudo o que entrou no negócio no ano, sem os aportes dos sócios.</p>
            <div style="display:flex;gap:12px;align-items:baseline;flex-wrap:wrap;">
                <span>Receita bruta do ano:</span>
                <strong style="font-size:22px;">{{ $money($declaration['total']) }}</strong>
                <span class="helper" style="margin:0;">para digitar: <code>{{ number_format($declaration['total'] / 100, 2, ',', '.') }}</code></span>
            </div>
            <div class="grid cards" style="margin-top:12px;">
                <div>
                    <h4 style="margin:0 0 6px;">Mês a mês</h4>
                    <div class="table-wrap"><table><tbody>
                        @foreach ($declaration['months'] as $m => $cents)
                            <tr><td>{{ \App\Finance\Transactions\TransactionFilters::monthName(\Illuminate\Support\Carbon::create($declaration['year'], $m, 1)) }}</td><td style="text-align:right;">{{ $money($cents) }}</td></tr>
                        @endforeach
                    </tbody></table></div>
                </div>
                <div>
                    <h4 style="margin:0 0 6px;">Por categoria</h4>
                    <p class="helper" style="margin:0 0 6px;">A declaração pede separado o que foi <b>venda (comércio e indústria)</b> e o que foi <b>serviço</b>. As categorias ajudam a dividir; na dúvida, pergunte ao contador.</p>
                    <div class="table-wrap"><table><tbody>
                        @forelse ($declaration['categories'] as $name => $cents)
                            <tr><td>{{ $name }}</td><td style="text-align:right;">{{ $money($cents) }}</td></tr>
                        @empty
                            <tr><td class="helper">Nenhum recebimento lançado em {{ $declaration['year'] }}.</td></tr>
                        @endforelse
                    </tbody></table></div>
                </div>
            </div>
            <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;">
                <a class="button secondary" href="{{ route('finance.partners', ['declaracao' => $declaration['year'], 'exportar' => 'planilha']) }}">Planilha</a>
                <a class="button secondary" href="{{ route('finance.partners', ['declaracao' => $declaration['year'], 'exportar' => 'pdf']) }}">PDF</a>
            </div>
        </div>
    @endif

    @if ($moves->isNotEmpty() || $settlements->isNotEmpty())
        <div class="card" style="margin-top:16px;">
            <h3 style="margin-top:0;">Movimentos dos sócios no período</h3>
            <div class="table-wrap"><table>
                <tbody>
                    @foreach ($moves as $t)
                        <tr>
                            <td style="white-space:nowrap;">{{ $t->date->format('d/m/Y') }}</td>
                            <td>{{ $t->description }}<div class="helper">{{ $t->account?->name }}@if ($t->linked_transaction_id) · também na carteira pessoal @endif</div></td>
                            <td class="num" style="{{ $t->partner_kind === 'aporte' ? 'color:var(--success-text);' : '' }}">{{ $t->partner_kind === 'aporte' ? '+ ' : '− ' }}{{ $money($t->amount_cents) }}</td>
                            @if ($canManage)<td style="text-align:right;"><a class="button secondary" href="{{ route('finance.transactions.edit', $t) }}">Editar</a></td>@endif
                        </tr>
                    @endforeach
                    @foreach ($settlements as $st)
                        <tr>
                            <td style="white-space:nowrap;">{{ $st->date->format('d/m/Y') }}</td>
                            <td>Acerto: {{ $st->from?->name }} pagou a {{ $st->to?->name }} <span class="helper">(por fora do caixa)</span></td>
                            <td class="num">{{ $money($st->amount_cents) }}</td>
                            @if ($canManage)<td style="text-align:right;"><form method="POST" action="{{ route('finance.partners.settle.destroy', $st) }}" onsubmit="return confirm('Apagar este acerto?');">@csrf<button type="submit" class="secondary">Apagar</button></form></td>@endif
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        </div>
    @endif

    {{-- Sócios e as partes (só o dono muda) --}}
    <div class="card" style="margin-top:16px;">
        <h3 style="margin-top:0;">Sócios</h3>
        @foreach ($partners as $p)
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;padding:8px 0;border-bottom:1px solid var(--border);@if ($p->archived_at) opacity:.6; @endif">
                @if ($canManagePartners)
                    <form method="POST" action="{{ route('finance.partners.update', $p) }}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
                        @csrf
                        <div><label>Nome<input name="name" value="{{ $p->name }}" maxlength="80" required></label></div>
                        <div><label>Parte (%)<input name="share" value="{{ $p->share_bp / 100 }}" inputmode="decimal" required style="max-width:90px;"></label></div>
                        <div><label>Login na carteira<select name="user_id"><option value="">— sem login —</option>@foreach ($members as $m)<option value="{{ $m->user_id }}" @selected((string) $p->user_id === (string) $m->user_id)>{{ $m->user?->name }}</option>@endforeach</select></label></div>
                        <div><button type="submit" class="secondary">Salvar</button></div>
                    </form>
                    <form method="POST" action="{{ route('finance.partners.archive', $p) }}">@csrf<input type="hidden" name="archive" value="{{ $p->archived_at ? 0 : 1 }}"><button type="submit" class="secondary">{{ $p->archived_at ? 'Voltar à sociedade' : 'Saiu da sociedade' }}</button></form>
                @else
                    <span><strong>{{ $p->name }}</strong> · {{ $p->shareLabel() }}</span>
                @endif
                @if ($p->mirror_account_id)<span class="helper">retiradas entram na carteira pessoal</span>@endif
            </div>
        @endforeach
        @if ($canManagePartners)
            <form method="POST" action="{{ route('finance.partners.store') }}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-top:12px;">
                @csrf
                <div><label for="p-name">Novo sócio</label><input id="p-name" name="name" maxlength="80" required placeholder="Nome"></div>
                <div><label for="p-share">Parte (%)</label><input id="p-share" name="share" inputmode="decimal" required style="max-width:90px;" value="{{ $active->isEmpty() ? 100 : max(0, 100 - $active->sum('share_bp') / 100) }}"></div>
                <div><label for="p-user">Login na carteira</label><select id="p-user" name="user_id"><option value="">— sem login —</option>@foreach ($members as $m)<option value="{{ $m->user_id }}">{{ $m->user?->name }}</option>@endforeach</select></div>
                <div><button type="submit">Cadastrar sócio</button></div>
            </form>
            <p class="helper" style="margin-bottom:0;">Com login, o sócio pode escolher receber as retiradas na carteira pessoal dele. Para dar login, convide em <a href="{{ route('finance.people') }}">Pessoas na carteira</a>.</p>
        @endif
    </div>
</x-core::layout>
