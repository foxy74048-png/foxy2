<x-core::layout title="Editar lançamento" product="Foxy Finanças" :subtitle="$currentWallet->name">
    <x-slot:nav>@include('finance.nav')</x-slot:nav>
    <x-slot:actions>@include('finance._add', ['label' => 'Lançamento', 'href' => route('finance.transactions').'#lancar'])</x-slot:actions>
    @php($t = $transaction)

    @include('finance.status')

    <form class="card stack" method="POST" action="{{ route('finance.transactions.update', $t) }}" style="max-width:720px;">
        @csrf
        <div>
            <strong>{{ \App\Finance\Transactions\Transaction::KINDS[$t->kind] }}</strong>
            @if ($label = $t->seriesLabel())<span class="muted">· {{ $t->series_kind === 'parcelas' ? 'parcela '.$label : $label }}</span>@endif
            @if ($t->isPlanned())<span class="badge">previsto</span>@endif
            <span class="helper">· lançado em {{ $t->created_at->format('d/m/Y H:i') }} ({{ \App\Finance\Transactions\Transaction::SOURCES[$t->source] ?? $t->source }})</span>
        </div>
        <div class="form-grid" style="align-items:end;">
            <div><label for="e-amount">Valor (R$)</label><input id="e-amount" name="amount" value="{{ old('amount', \App\Platform\Support\Money::input($t->amount_cents)) }}" required inputmode="decimal"></div>
            <div><label for="e-desc">Descrição</label><input id="e-desc" name="description" value="{{ old('description', $t->description) }}" required maxlength="160"></div>
            @unless ($t->isTransfer())
                <div>
                    <label for="e-cat">Categoria</label>
                    <select id="e-cat" name="category_id">
                        <option value="">— sem categoria —</option>
                        @foreach ($categoryOptions[$t->kind] ?? [] as $group => $items)<optgroup label="{{ $group }}">@foreach ($items as $id => $name)<option value="{{ $id }}" @selected(old('category_id', $t->category_id) === $id)>{{ $name }}</option>@endforeach</optgroup>@endforeach
                    </select>
                </div>
            @endunless
            <div>
                <label for="e-account">{{ $t->isTransfer() ? 'Saiu de' : 'Conta ou cartão' }}</label>
                <select id="e-account" name="account_id" required>@foreach ($accounts as $a)<option value="{{ $a->id }}" @selected(old('account_id', $t->account_id) === $a->id)>{{ $a->name }}</option>@endforeach</select>
            </div>
            @if ($t->isTransfer())
                <div>
                    <label for="e-to">Foi para</label>
                    <select id="e-to" name="to_account_id" required>@foreach ($accounts as $a)<option value="{{ $a->id }}" @selected(old('to_account_id', $t->to_account_id) === $a->id)>{{ $a->name }}</option>@endforeach</select>
                </div>
            @endif
            <div><label for="e-date">Data</label><input id="e-date" name="date" type="date" value="{{ old('date', $t->date->toDateString()) }}" required></div>
            <div style="grid-column:1/-1;"><label for="e-notes">Observação</label><input id="e-notes" name="notes" value="{{ old('notes', $t->notes) }}" maxlength="2000"></div>
        </div>
        @if ($seriesCount > 0)
            <label style="display:flex;gap:8px;align-items:center;font-weight:400;"><input type="checkbox" name="following" value="1" @checked(old('following', true)) style="width:auto;"> Mudar também os próximos {{ $seriesCount }} desta série (a data muda só neste)</label>
        @endif
        @if ($t->invoice_month)<div class="helper">Cai na fatura de {{ \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $t->invoice_month.'-01')->format('m/Y') }}.</div>@endif
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <button type="submit">Salvar</button>
            <a class="button secondary" href="{{ route('finance.transactions', ['mes' => $t->date->format('Y-m')]) }}">Voltar</a>
        </div>
    </form>

    <div class="card stack" style="max-width:720px;margin-top:16px;">
        @if ($t->isPlanned())
            <form method="POST" action="{{ route('finance.transactions.confirm', $t) }}" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;">
                @csrf
                <div><label for="c-date">{{ $t->kind === 'recebimento' ? 'Recebi em' : 'Paguei em' }}</label><input id="c-date" name="date" type="date" value="{{ today()->toDateString() }}"></div>
                <button type="submit">Confirmar</button>
            </form>
        @endif
        <form method="POST" action="{{ route('finance.transactions.destroy', $t) }}" onsubmit="return confirm('Apagar este lançamento?');" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
            @csrf
            @if ($seriesCount > 0)<label style="display:flex;gap:6px;align-items:center;font-weight:400;"><input type="checkbox" name="following" value="1" style="width:auto;"> e os próximos {{ $seriesCount }} da série</label>@endif
            <button type="submit" class="danger">Apagar</button>
        </form>
    </div>
</x-core::layout>
