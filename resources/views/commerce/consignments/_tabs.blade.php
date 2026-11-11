{{-- Abas da consignação: o que a loja deixou com outras pessoas e o que as fornecedoras deixaram com a loja. --}}
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;" role="tablist">
    <a role="tab" aria-selected="{{ $tab === 'enviadas' ? 'true' : 'false' }}" href="{{ route('commerce.consignments') }}" @class(['button', 'secondary' => $tab !== 'enviadas'])>Enviadas (maletas com clientes e revendedoras)</a>
    @if (\App\Commerce\Access::allows($currentMembership, 'commerce.catalog.manage') && \App\Commerce\Access::allows($currentMembership, 'commerce.stock.adjust'))
        <a role="tab" aria-selected="{{ $tab === 'recebidas' ? 'true' : 'false' }}" href="{{ route('commerce.consignments.received') }}" @class(['button', 'secondary' => $tab !== 'recebidas'])>Recebidas (fornecedoras)</a>
    @endif
</div>
