<x-core::layout :title="$group->name" product="Foxy Finanças" :subtitle="$currentWallet->name">
    <x-slot:nav>@include('finance.nav')</x-slot:nav>
    <x-slot:actions>@include('platform.export-buttons')@if ($canManage && ! $group->isClosed())@include('finance._add', ['label' => 'Despesa', 'target' => 'despesa'])@endif</x-slot:actions>
    @php($money = fn ($c) => \App\Platform\Support\Money::format($c))
    @php($input = fn ($c) => \App\Platform\Support\Money::input($c))
    @php($open = $canManage && ! $group->isClosed())

    @include('finance.status')

    <p style="margin:0 0 12px;"><a href="{{ route('finance.groups') }}">← Despesas em grupo</a>@if ($group->isClosed()) <span class="badge">encerrado</span>@endif</p>

    <div class="grid cards">
        <div class="card"><div class="muted">Total do grupo</div><h2 style="margin:4px 0;">{{ $money($balances->sum('share')) }}</h2><div class="helper">{{ $expenses->count() }} despesa(s) · {{ $members->count() }} pessoas</div></div>
        @php($me = $balances->first(fn ($b) => $b['member']->is_me))
        <div class="card"><div class="muted">Sua parte</div><h2 style="margin:4px 0;">{{ $money($me['share'] ?? 0) }}</h2><div class="helper">você pagou {{ $money($me['paid'] ?? 0) }}</div></div>
        <div class="card"><div class="muted">Você</div>
            @if (($me['net'] ?? 0) > 0)<h2 style="margin:4px 0;color:var(--success-text);">recebe {{ $money($me['net']) }}</h2>
            @elseif (($me['net'] ?? 0) < 0)<h2 style="margin:4px 0;color:var(--danger-text);">deve {{ $money(-$me['net']) }}</h2>
            @else <h2 style="margin:4px 0;">está quite</h2>
            @endif
        </div>
    </div>

    {{-- Quem paga quem: o principal, sempre à vista --}}
    <div class="card" style="margin-top:16px;">
        <h3 style="margin-top:0;">Quem paga quem</h3>
        @if (! $settlements)
            <div class="empty">{{ $expenses->isEmpty() ? 'Lance as despesas para ver as contas.' : 'Tudo acertado: ninguém deve ninguém.' }}</div>
        @else
            @foreach ($settlements as $s)
                <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;align-items:center;padding:8px 0;border-bottom:1px solid var(--border);">
                    <span><strong>{{ $s['from']->label() }}</strong> paga <strong>{{ $s['to']->label() }}</strong>: <strong>{{ $money($s['amount']) }}</strong></span>
                    @if ($open)
                        <form method="POST" action="{{ route('finance.groups.payments.add', $group) }}">@csrf
                            <input type="hidden" name="from" value="{{ $s['from']->id }}"><input type="hidden" name="to" value="{{ $s['to']->id }}">
                            <input type="hidden" name="amount" value="{{ $input($s['amount']) }}"><input type="hidden" name="date" value="{{ today()->toDateString() }}">
                            <button type="submit" class="secondary">Já pagou</button>
                        </form>
                    @endif
                </div>
            @endforeach
            <p class="helper" style="margin-bottom:0;">É o menor número de pagamentos para zerar as contas de todo mundo.</p>
        @endif
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;">
            <a class="button secondary" href="{{ $whatsapp }}" target="_blank" rel="noopener">Mandar o resumo no WhatsApp</a>
            <button type="button" class="secondary" onclick="navigator.clipboard.writeText(@js($link)).then(() => this.textContent = 'Link copiado')">Copiar link para o grupo ver</button>
        </div>
        <p class="helper" style="margin-bottom:0;">O link mostra o resumo e as despesas, só para ver (sem login). @if ($canManage)<form method="POST" action="{{ route('finance.groups.link', $group) }}" style="display:inline;" onsubmit="return confirm('Criar um link novo? O antigo para de funcionar.');">@csrf<button type="submit" class="secondary" style="padding:2px 8px;">trocar o link</button></form>@endif</p>
    </div>

    <div class="card" style="margin-top:16px;">
        <h3 style="margin-top:0;">Quanto cada um gastou e pagou</h3>
        <div class="table-wrap"><table>
            <thead><tr><th>Pessoa</th><th class="num">Gastou (a parte dela)</th><th class="num">Pagou</th><th class="num">Acertos</th><th class="num">Recebe / deve</th>@if ($open)<th></th>@endif</tr></thead>
            <tbody>
                @foreach ($balances as $b)
                    <tr>
                        <td><strong>{{ $b['member']->label() }}</strong></td>
                        <td class="num">{{ $money($b['share']) }}</td>
                        <td class="num">{{ $money($b['paid']) }}</td>
                        <td class="num">@if ($b['sent'] || $b['received'])@if ($b['sent'])passou {{ $money($b['sent']) }} @endif @if ($b['received'])recebeu {{ $money($b['received']) }} @endif @else — @endif</td>
                        <td class="num" style="{{ $b['net'] > 0 ? 'color:var(--success-text);' : ($b['net'] < 0 ? 'color:var(--danger-text);' : '') }}">{{ $b['net'] > 0 ? 'recebe '.$money($b['net']) : ($b['net'] < 0 ? 'deve '.$money(-$b['net']) : 'quite') }}</td>
                        @if ($open)<td style="text-align:right;">@unless ($b['member']->is_me)<form method="POST" action="{{ route('finance.groups.members.remove', $b['member']) }}" onsubmit="return confirm('Tirar {{ $b['member']->name }} do grupo?');">@csrf<button type="submit" class="secondary" title="Só sem despesas nem pagamentos">Tirar</button></form>@endunless</td>@endif
                    </tr>
                @endforeach
            </tbody>
        </table></div>
        @if ($open)
            <form method="POST" action="{{ route('finance.groups.members.add', $group) }}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-top:12px;">
                @csrf
                <div><label for="m-name">Mais alguém</label><input id="m-name" name="name" maxlength="80" placeholder="Nome"></div>
                @if ($contacts->isNotEmpty())<div><label for="m-contact">ou de "Quem me deve"</label><select id="m-contact" name="contact_id"><option value="">—</option>@foreach ($contacts as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>@endif
                <div><button type="submit" class="secondary">Pôr no grupo</button></div>
            </form>
        @endif
    </div>

    @if ($open)
        <form class="card stack" id="despesa" method="POST" action="{{ route('finance.groups.expenses.add', $group) }}" style="margin-top:16px;" data-group-expense>
            @csrf
            <h3 style="margin:0;">Nova despesa</h3>
            <div class="form-grid" style="align-items:end;">
                <div><label for="e-desc">O que foi</label><input id="e-desc" name="description" required maxlength="160" placeholder="Ex.: Mercado, gasolina, pousada"></div>
                <div><label for="e-amount">Valor (R$)</label><input id="e-amount" name="amount" inputmode="decimal" required></div>
                <div><label for="e-payer">Quem pagou</label><select id="e-payer" name="paid_by">@foreach ($members as $m)<option value="{{ $m->id }}">{{ $m->label() }}</option>@endforeach</select></div>
                <div><label for="e-date">Data</label><input id="e-date" name="date" type="date" value="{{ today()->toDateString() }}" required></div>
            </div>
            <div style="display:flex;gap:16px;flex-wrap:wrap;">
                <label style="display:flex;gap:6px;align-items:center;font-weight:400;"><input type="radio" name="mode" value="igual" checked style="width:auto;"> Dividir igual entre</label>
                <label style="display:flex;gap:6px;align-items:center;font-weight:400;"><input type="radio" name="mode" value="valores" style="width:auto;"> Cada um com um valor</label>
            </div>
            <div style="display:flex;gap:16px;flex-wrap:wrap;" data-mode="igual">
                @foreach ($members as $m)<label style="display:flex;gap:6px;align-items:center;font-weight:400;"><input type="checkbox" name="participants[]" value="{{ $m->id }}" checked style="width:auto;"> {{ $m->label() }}</label>@endforeach
            </div>
            <div style="display:none;gap:12px;flex-wrap:wrap;" data-mode="valores">
                @foreach ($members as $m)<label style="display:flex;gap:6px;align-items:center;font-weight:400;">{{ $m->label() }} <input name="amounts[{{ $m->id }}]" inputmode="decimal" placeholder="R$" style="max-width:100px;"></label>@endforeach
            </div>
            <div><button type="submit">Lançar despesa</button></div>
        </form>
    @endif

    <div class="card" style="margin-top:16px;">
        <h3 style="margin-top:0;">Despesas</h3>
        @if ($expenses->isEmpty())
            <div class="empty">Nenhuma despesa ainda.</div>
        @else
            <div class="table-wrap"><table>
                <thead><tr><th>Data</th><th>O quê</th><th>Quem pagou</th><th class="num">Valor</th><th>Dividido entre</th>@if ($open)<th></th>@endif</tr></thead>
                <tbody>
                    @foreach ($expenses as $e)
                        <tr>
                            <td style="white-space:nowrap;">{{ $e->date->format('d/m') }}</td>
                            <td><strong>{{ $e->description }}</strong></td>
                            <td>{{ $e->payer?->label() }}</td>
                            <td class="num">{{ $money($e->amount_cents) }}</td>
                            <td class="helper">{{ $e->shares->map(fn ($s) => $members->firstWhere('id', $s->member_id)?->label().($e->split_mode === 'valores' ? ' '.$money($s->amount_cents) : ''))->join(', ') }}</td>
                            @if ($open)<td style="text-align:right;"><form method="POST" action="{{ route('finance.groups.expenses.destroy', $e) }}" onsubmit="return confirm('Apagar esta despesa?');">@csrf<button type="submit" class="secondary">Apagar</button></form></td>@endif
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif

        @if ($payments->isNotEmpty())
            <h3>Pagamentos entre vocês</h3>
            @foreach ($payments as $p)
                <div style="display:flex;justify-content:space-between;gap:8px;align-items:center;padding:6px 0;border-bottom:1px solid var(--border);">
                    <span>{{ $p->date->format('d/m') }} · {{ $p->from?->label() }} → {{ $p->to?->label() }} · <strong>{{ $money($p->amount_cents) }}</strong></span>
                    @if ($open)<form method="POST" action="{{ route('finance.groups.payments.destroy', $p) }}">@csrf<button type="submit" class="secondary">Apagar</button></form>@endif
                </div>
            @endforeach
        @endif
    </div>

    @if ($canManage)
        <div class="card" style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            @if (! $group->sent_to_debts_at)
                <form method="POST" action="{{ route('finance.groups.debts', $group) }}" onsubmit="return confirm('Levar o que falta acertar com você para Quem me deve e encerrar o grupo?');">@csrf<button type="submit">Encerrar e levar para "Quem me deve"</button></form>
            @else
                <span class="helper">Levado para "Quem me deve" em {{ $group->sent_to_debts_at->format('d/m/Y') }}.</span>
            @endif
            <form method="POST" action="{{ route('finance.groups.close', $group) }}">@csrf<input type="hidden" name="close" value="{{ $group->isClosed() ? 0 : 1 }}"><button type="submit" class="secondary">{{ $group->isClosed() ? 'Reabrir' : 'Só encerrar' }}</button></form>
            <form method="POST" action="{{ route('finance.groups.destroy', $group) }}" onsubmit="return confirm('Apagar o grupo e todas as despesas dele?');">@csrf<button type="submit" class="danger">Apagar grupo</button></form>
        </div>
    @endif

    <x-slot:scripts>
    <script>
    (function () {
        var form = document.querySelector('[data-group-expense]');
        if (!form) return;
        function sync() {
            var mode = form.querySelector('input[name=mode]:checked').value;
            form.querySelectorAll('[data-mode]').forEach(function (el) { el.style.display = el.getAttribute('data-mode') === mode ? 'flex' : 'none'; el.querySelectorAll('input').forEach(function (i) { i.disabled = el.getAttribute('data-mode') !== mode; }); });
        }
        form.querySelectorAll('input[name=mode]').forEach(function (r) { r.addEventListener('change', sync); });
        sync();
    })();
    </script>
    </x-slot:scripts>
</x-core::layout>
