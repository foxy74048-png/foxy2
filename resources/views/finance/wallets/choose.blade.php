<x-core::plain title="Escolher carteira" product="Foxy Finanças">
    <div>
        <h2 style="margin:0 0 4px">Qual carteira você quer abrir?</h2>
        <p class="muted" style="margin:0">Depois dá para trocar pelo menu, a qualquer hora.</p>
    </div>
    @foreach ($options as $o)
        <form method="POST" action="{{ route('finance.wallets.switch') }}">
            @csrf
            <input type="hidden" name="organization_id" value="{{ $o['membership']->organization_id }}">
            <button type="submit" class="secondary" style="width:100%;text-align:left;">
                <strong>{{ $o['wallet']?->name ?? $o['membership']->organization->name }}</strong> · {{ $o['wallet']?->kindLabel() }} · {{ $o['role'] }}
            </button>
        </form>
    @endforeach
    <p class="helper" style="margin:0;"><a href="{{ route('finance.wallets.new') }}">Abrir outra carteira</a></p>
    <form method="POST" action="{{ route('finance.logout') }}">
        @csrf
        <button type="submit" class="secondary" style="width:100%;">Sair</button>
    </form>
</x-core::plain>
