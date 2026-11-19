<x-core::layout title="Orçamento" product="Foxy Finanças" :subtitle="$currentWallet->name">
    <x-slot:nav>@include('finance.nav')</x-slot:nav>
    <x-slot:actions>@include('platform.export-buttons')@if ($canManage)@include('finance._add', ['label' => 'Novo limite', 'target' => 'novo-limite'])@endif</x-slot:actions>
    @php($money = fn ($c) => \App\Platform\Support\Money::format($c))
    @php($input = fn ($c) => \App\Platform\Support\Money::input($c))

    @include('finance.status')

    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
        <a class="button secondary" href="{{ route('finance.budgets', ['mes' => $month->copy()->subMonthNoOverflow()->format('Y-m')]) }}" aria-label="Mês anterior">‹</a>
        <h2 style="margin:0;font-size:1.2rem;">{{ \Illuminate\Support\Str::ucfirst(\App\Finance\Transactions\TransactionFilters::monthName($month)) }}</h2>
        <a class="button secondary" href="{{ route('finance.budgets', ['mes' => $month->copy()->addMonthNoOverflow()->format('Y-m')]) }}" aria-label="Mês seguinte">›</a>
    </div>

    @if ($rows->isNotEmpty())
        <div class="grid cards">
            <div class="card"><div class="muted">Limite somado</div><h2 style="margin:4px 0;">{{ $money($totalLimit) }}</h2></div>
            <div class="card"><div class="muted">Gasto nessas categorias</div><h2 style="margin:4px 0;{{ $totalSpent > $totalLimit ? 'color:var(--danger-text);' : '' }}">{{ $money($totalSpent) }}</h2></div>
            <div class="card"><div class="muted">Passaram do limite</div><h2 style="margin:4px 0;">{{ $rows->where('status', 'estourou')->count() }}</h2><div class="helper">{{ $rows->where('status', 'perto')->count() }} perto (80% ou mais)</div></div>
        </div>
    @endif

    @if ($canManage)
        <form class="card form-grid" id="novo-limite" method="POST" action="{{ route('finance.budgets.store') }}" style="margin-top:16px;align-items:end;">
            @csrf
            <div style="grid-column:1/-1;"><h3 style="margin:0;">Novo limite</h3><p class="helper" style="margin:4px 0 0;">Escolha o grupo (vale para tudo dentro dele) ou uma categoria só. Avisamos aqui e no Início ao passar de 80% e de 100%.</p></div>
            <div>
                <label for="b-cat">Categoria</label>
                <select id="b-cat" name="category_id" required><option value="">— escolha —</option>@foreach ($categoryOptions as $group => $items)<optgroup label="{{ $group }}">@foreach ($items as $id => $name)<option value="{{ $id }}" @selected(old('category_id') === $id)>{{ $name }}</option>@endforeach</optgroup>@endforeach</select>
            </div>
            <div><label for="b-amount">Limite por mês (R$)</label><input id="b-amount" name="amount" value="{{ old('amount') }}" inputmode="decimal" required></div>
            <label style="display:flex;gap:6px;align-items:center;font-weight:400;"><input type="checkbox" name="only_month" value="{{ $month->format('Y-m') }}" style="width:auto;"> só em {{ $month->format('m/Y') }} (senão, todo mês)</label>
            <div><button type="submit">Salvar limite</button></div>
        </form>

        @if ($suggestions->isNotEmpty())
            <div class="card" style="margin-top:16px;">
                <h3 style="margin-top:0;">Sugestão para começar</h3>
                <p class="helper" style="margin-top:0;">Onde você mais gasta e ainda não tem limite. O valor sugerido é 10% abaixo do que você gasta (a média dos últimos 3 meses ou, se for maior, o deste mês).</p>
                <div class="stack">
                    @foreach ($suggestions as $sug)
                        @php($base = max($sug['avg'], $sug['total']))
                        <form method="POST" action="{{ route('finance.budgets.store') }}" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                            @csrf<input type="hidden" name="category_id" value="{{ $sug['id'] }}">
                            <span style="min-width:180px;"><strong>{{ $sug['name'] }}</strong><div class="helper">@if ($sug['avg'])média {{ $money($sug['avg']) }} · @endif neste mês {{ $money($sug['total']) }}</div></span>
                            <input name="amount" value="{{ $input((int) (round($base * 0.9 / 1000) * 1000) ?: $base) }}" inputmode="decimal" style="max-width:120px;" aria-label="Limite para {{ $sug['name'] }}">
                            <button type="submit" class="secondary">Usar este limite</button>
                        </form>
                    @endforeach
                </div>
            </div>
        @endif
    @endif

    <div class="card" style="margin-top:16px;">
        <h3 style="margin-top:0;">Limites</h3>
        @if ($rows->isEmpty())
            <div class="empty">Nenhum limite ainda.</div>
        @else
            <div class="stack">
                @foreach ($rows as $b)
                    <div style="display:grid;grid-template-columns:1fr auto;gap:12px;align-items:center;">
                        <div>@include('finance._budget-bar', ['b' => $b])<div class="helper">{{ $b['budget']->month ? 'só em '.\Illuminate\Support\Carbon::createFromFormat('Y-m-d', $b['budget']->month.'-01')->format('m/Y') : 'todo mês' }}</div></div>
                        @if ($canManage)
                            <div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;">
                                <form method="POST" action="{{ route('finance.budgets.update', $b['budget']) }}" style="display:flex;gap:6px;">@csrf<input name="amount" value="{{ $input($b['limit']) }}" inputmode="decimal" style="max-width:110px;" aria-label="Limite"><button type="submit" class="secondary">Salvar</button></form>
                                <form method="POST" action="{{ route('finance.budgets.destroy', $b['budget']) }}" onsubmit="return confirm('Apagar este limite?');">@csrf<button type="submit" class="danger">Apagar</button></form>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-core::layout>
