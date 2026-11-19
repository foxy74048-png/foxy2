<x-core::layout title="Quem me deve" product="Foxy Finanças" :subtitle="$currentWallet->name">
    <x-slot:nav>@include('finance.nav')</x-slot:nav>
    <x-slot:actions>@include('platform.export-buttons')@if ($canManage)@include('finance._add', ['label' => 'Registrar', 'target' => 'registrar'])@endif</x-slot:actions>
    @php($money = fn ($c) => \App\Platform\Support\Money::format($c))
    @php($contactField = function (string $prefix) use ($contacts) {
        $html = '<select name="'.$prefix.'contact_id" aria-label="Pessoa"><option value="">— nova pessoa (escreva ao lado) —</option>';
        foreach ($contacts as $c) { $html .= '<option value="'.e($c->id).'">'.e($c->name).'</option>'; }
        return $html.'</select>';
    })

    @include('finance.status')

    <div class="grid cards">
        <div class="card"><div class="muted">Te devem</div><h2 style="margin:4px 0;color:var(--success-text);">{{ $money($totalOwedToMe) }}</h2><div class="helper">{{ $owedToMe->count() }} pessoa(s)</div></div>
        <div class="card"><div class="muted">Você deve</div><h2 style="margin:4px 0;{{ $totalIOwe > 0 ? 'color:var(--danger-text);' : '' }}">{{ $money($totalIOwe) }}</h2><div class="helper">{{ $iOwe->count() }} pessoa(s)</div></div>
        <div class="card"><div class="muted">Objetos emprestados</div><h2 style="margin:4px 0;">{{ $objects->count() }}</h2><div class="helper">ainda com as pessoas</div></div>
    </div>

    @if ($canManage)
        {{-- Registrar: sempre aberto --}}
        <form class="card form-grid" id="registrar" method="POST" action="{{ route('finance.debts.store') }}" style="margin-top:16px;align-items:end;">
            @csrf
            <div style="grid-column:1/-1;"><h3 style="margin:0;">Emprestei, peguei emprestado ou devolveram</h3>
                <p class="helper" style="margin:4px 0 0;">Escolhendo a conta, o dinheiro sai (ou entra) nela como transferência para "Amigos e família": <b>emprestar não conta como gasto</b> e receber de volta não conta como renda.</p></div>
            <div><label>Pessoa</label>{!! $contactField('') !!}</div>
            <div><label for="d-name">Ou nova: nome</label><input id="d-name" name="contact_name" value="{{ old('contact_name') }}" maxlength="80" placeholder="Ex.: João"></div>
            <div>
                <label for="d-kind">O que aconteceu</label>
                <select id="d-kind" name="kind">@foreach (['emprestei', 'peguei', 'devolveu', 'paguei'] as $k)<option value="{{ $k }}" @selected(old('kind') === $k)>{{ \App\Finance\Debts\PersonEntry::KINDS[$k][0] }}</option>@endforeach</select>
            </div>
            <div><label for="d-amount">Valor (R$)</label><input id="d-amount" name="amount" value="{{ old('amount') }}" inputmode="decimal" required></div>
            <div><label for="d-date">Data</label><input id="d-date" name="date" type="date" value="{{ old('date', today()->toDateString()) }}" required></div>
            <div>
                <label for="d-account">Saiu de / entrou em (opcional)</label>
                <select id="d-account" name="account_id"><option value="">— só anotar —</option>@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select>
            </div>
            <div><label for="d-due">Combinado para (opcional)</label><input id="d-due" name="due_date" type="date" value="{{ old('due_date') }}"></div>
            <div><label for="d-desc">Para quê (opcional)</label><input id="d-desc" name="description" value="{{ old('description') }}" maxlength="160" placeholder="Ex.: conserto do carro"></div>
            <div><button type="submit">Registrar</button></div>
        </form>

        {{-- Dividir uma conta: sempre aberto --}}
        <form class="card stack" method="POST" action="{{ route('finance.debts.split') }}" style="margin-top:16px;" data-split>
            @csrf
            <div><h3 style="margin:0;">Dividir uma conta</h3><p class="helper" style="margin:4px 0 0;">Você pagou tudo (o bar, o presente, a viagem): no seu gasto entra só a sua parte, e cada pessoa passa a te dever a dela.</p></div>
            <div class="form-grid" style="align-items:end;">
                <div><label for="s-desc">O que foi</label><input id="s-desc" name="description" required maxlength="160" placeholder="Ex.: Bar de sexta"></div>
                <div><label for="s-total">Total que você pagou (R$)</label><input id="s-total" name="total" inputmode="decimal" required data-split-total></div>
                <div><label for="s-account">Pagou com</label><select id="s-account" name="account_id" required>@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}{{ $a->isCard() ? ' (cartão)' : '' }}</option>@endforeach</select></div>
                <div><label for="s-cat">Categoria da sua parte</label><select id="s-cat" name="category_id"><option value="">— sem categoria —</option>@foreach ($categoryOptions as $group => $items)<optgroup label="{{ $group }}">@foreach ($items as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</optgroup>@endforeach</select></div>
                <div><label for="s-date">Data</label><input id="s-date" name="date" type="date" value="{{ today()->toDateString() }}" required></div>
                <div><label for="s-my">A sua parte (R$)</label><input id="s-my" name="my_share" inputmode="decimal" data-split-my placeholder="o que sobrar"></div>
            </div>
            @for ($i = 0; $i < 4; $i++)
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                    <select name="people[{{ $i }}][contact_id]" aria-label="Pessoa {{ $i + 1 }}" style="max-width:220px;"><option value="">— nova pessoa —</option>@foreach ($contacts as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select>
                    <input name="people[{{ $i }}][name]" placeholder="ou o nome" maxlength="80" style="max-width:180px;" aria-label="Nome da pessoa {{ $i + 1 }}">
                    <input name="people[{{ $i }}][amount]" inputmode="decimal" placeholder="deve R$" style="max-width:120px;" aria-label="Quanto a pessoa {{ $i + 1 }} deve" data-split-share>
                </div>
            @endfor
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button type="button" class="secondary" data-split-equal>Dividir igualmente (com você)</button>
                <button type="submit">Dividir</button>
            </div>
        </form>
    @endif

    <div class="grid two" style="align-items:start;margin-top:16px;">
        <div class="card">
            <h3 style="margin-top:0;">Te devem</h3>
            @forelse ($owedToMe as $c)
                <a href="{{ route('finance.debts.show', $c) }}" style="display:flex;justify-content:space-between;gap:8px;padding:8px 0;border-bottom:1px solid var(--border);text-decoration:none;color:inherit;"><strong>{{ $c->name }}</strong><strong style="color:var(--success-text);">{{ $money($c->balanceCents()) }}</strong></a>
            @empty
                <div class="empty">Ninguém te deve agora.</div>
            @endforelse
        </div>
        <div class="card">
            <h3 style="margin-top:0;">Você deve</h3>
            @forelse ($iOwe as $c)
                <a href="{{ route('finance.debts.show', $c) }}" style="display:flex;justify-content:space-between;gap:8px;padding:8px 0;border-bottom:1px solid var(--border);text-decoration:none;color:inherit;"><strong>{{ $c->name }}</strong><strong style="color:var(--danger-text);">{{ $money(-$c->balanceCents()) }}</strong></a>
            @empty
                <div class="empty">Você não deve a ninguém.</div>
            @endforelse
        </div>
    </div>

    <div class="card" style="margin-top:16px;">
        <h3 style="margin-top:0;">Objetos emprestados</h3>
        @if ($canManage)
            <form method="POST" action="{{ route('finance.debts.object') }}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-bottom:12px;">
                @csrf
                <div><label>Com quem</label>{!! $contactField('') !!}</div>
                <div><label for="o-name">Ou nova</label><input id="o-name" name="contact_name" maxlength="80" style="max-width:160px;"></div>
                <div><label for="o-what">O quê</label><input id="o-what" name="what" required maxlength="160" placeholder="Ex.: furadeira, livro"></div>
                <div><label for="o-date">Desde</label><input id="o-date" name="date" type="date" value="{{ today()->toDateString() }}" required></div>
                <div><button type="submit" class="secondary">Anotar</button></div>
            </form>
        @endif
        @forelse ($objects as $o)
            <div style="display:flex;justify-content:space-between;gap:8px;align-items:center;padding:6px 0;border-bottom:1px solid var(--border);">
                <span><strong>{{ $o->description }}</strong> com <a href="{{ route('finance.debts.show', $o->contact) }}">{{ $o->contact?->name }}</a> <span class="helper">desde {{ $o->date->format('d/m/Y') }} ({{ (int) $o->date->diffInDays(today()) }} dias)</span></span>
                @if ($canManage)<form method="POST" action="{{ route('finance.debts.return', $o) }}">@csrf<button type="submit" class="secondary">Já devolveu</button></form>@endif
            </div>
        @empty
            <div class="empty">Nenhum objeto emprestado.</div>
        @endforelse
    </div>

    @if ($settled->isNotEmpty())
        <p class="helper" style="margin-top:12px;">Quites: @foreach ($settled as $c)<a href="{{ route('finance.debts.show', $c) }}">{{ $c->name }}</a>{{ $loop->last ? '' : ', ' }}@endforeach</p>
    @endif

    <x-slot:scripts>
    <script>
    (function () {
        var form = document.querySelector('[data-split]');
        if (!form) return;
        var parse = function (v) { v = (v || '').replace(/[^\d,.-]/g, ''); if (v.indexOf(',') >= 0) v = v.replace(/\./g, '').replace(',', '.'); var n = parseFloat(v); return isNaN(n) ? 0 : Math.round(n * 100); };
        var fmt = function (c) { return (c / 100).toFixed(2).replace('.', ','); };
        form.querySelector('[data-split-equal]').addEventListener('click', function () {
            var total = parse(form.querySelector('[data-split-total]').value);
            var rows = Array.prototype.filter.call(form.querySelectorAll('[data-split-share]'), function (input) {
                var row = input.parentNode; return row.querySelector('select').value !== '' || row.querySelector('input[name$="[name]"]').value.trim() !== '';
            });
            if (!total || !rows.length) { alert('Informe o total e escolha (ou escreva) as pessoas.'); return; }
            var each = Math.floor(total / (rows.length + 1));
            rows.forEach(function (input) { input.value = fmt(each); });
            form.querySelector('[data-split-my]').value = fmt(total - each * rows.length);
        });
    })();
    </script>
    </x-slot:scripts>
</x-core::layout>
