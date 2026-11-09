{{-- Abas do estoque (só aparecem para quem pode dar entrada). --}}
@if (\App\Commerce\Access::allows($currentMembership, 'commerce.stock.adjust'))
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;" role="tablist">
        <a role="tab" aria-selected="{{ $tab === 'posicao' ? 'true' : 'false' }}" href="{{ route('commerce.stock') }}" @class(['button', 'secondary' => $tab !== 'posicao'])>Posição do estoque</a>
        <a role="tab" aria-selected="{{ $tab === 'entrada' ? 'true' : 'false' }}" href="{{ route('commerce.stock', ['aba' => 'entrada']) }}" @class(['button', 'secondary' => $tab !== 'entrada'])>Entrada de mercadoria</a>
        @if (\App\Commerce\Access::allows($currentMembership, 'commerce.catalog.manage'))
            <a role="tab" aria-selected="{{ $tab === 'remessas' ? 'true' : 'false' }}" href="{{ route('commerce.supply') }}" @class(['button', 'secondary' => $tab !== 'remessas'])>Remessas de fornecedor (importar)</a>
        @endif
    </div>
@endif
