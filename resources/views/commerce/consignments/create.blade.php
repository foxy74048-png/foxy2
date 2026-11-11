<x-core::layout title="Nova maleta" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>
    <x-slot:actions><a class="button secondary" href="{{ route('commerce.consignments') }}">Voltar às maletas</a></x-slot:actions>

    @include('commerce.status')

    <form method="POST" action="{{ route('commerce.consignments.store') }}" class="stack">
        @csrf
        <div class="card form-grid" style="align-items:end;">
            <div style="grid-column: 1 / -1;"><h3 style="margin:0;">Com quem a maleta vai ficar</h3></div>
            <div>
                <label for="customer_id">Cliente ou revendedora já cadastrada</label>
                <select id="customer_id" name="customer_id">
                    <option value="">— nova pessoa (preencha ao lado) —</option>
                    @foreach ($customers as $c)<option value="{{ $c->id }}" @selected(old('customer_id', $customer?->id) === $c->id)>{{ $c->name }}{{ $c->phone ? ' · '.$c->phone : '' }}</option>@endforeach
                </select>
            </div>
            <div><label for="customer_name">Ou nome de uma pessoa nova</label><input id="customer_name" name="customer_name" value="{{ old('customer_name') }}" maxlength="255"></div>
            <div><label for="customer_phone">WhatsApp</label><input id="customer_phone" name="customer_phone" value="{{ old('customer_phone') }}" maxlength="30" placeholder="11 99999-9999"></div>
            <div>
                <label for="kind">É</label>
                <select id="kind" name="kind">@foreach (\App\Commerce\Consignment\Consignment::KINDS as $k => $l)<option value="{{ $k }}" @selected(old('kind', $last?->kind ?? 'cliente') === $k)>{{ $l }}</option>@endforeach</select>
            </div>
            <div data-commission><label for="commission_percent">Comissão da revendedora (%)</label><input id="commission_percent" name="commission_percent" type="number" min="0" max="100" step="0.5" value="{{ old('commission_percent', $last?->kind === 'revendedora' ? $last->commission_percent : 30) }}"><div class="helper">sai como desconto no acerto: ela te paga o vendido menos a comissão</div></div>
            <div><label for="sent_on">Saiu em</label><input id="sent_on" name="sent_on" type="date" value="{{ old('sent_on', today()->toDateString()) }}"></div>
            <div><label for="due_on">Acerto / volta até</label><input id="due_on" name="due_on" type="date" value="{{ old('due_on', today()->addDays(7)->toDateString()) }}"></div>
            <div style="grid-column: 1 / -1;"><label for="notes">Observação (opcional)</label><input id="notes" name="notes" value="{{ old('notes') }}" maxlength="2000"></div>
        </div>

        <div class="card">
            <h3 style="margin-top:0;">Peças da maleta</h3>
            @include('commerce.consignments._picker', ['pickable' => $pickable])
        </div>

        <div><button type="submit">Registrar maleta e reservar as peças</button></div>
    </form>
    <script>
        (function () {
            const kind = document.getElementById('kind'), box = document.querySelector('[data-commission]');
            const sync = function () { box.style.display = kind.value === 'revendedora' ? '' : 'none'; };
            kind.addEventListener('change', sync); sync();
        })();
    </script>
</x-core::layout>
