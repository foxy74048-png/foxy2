<x-core::plain title="Escolher loja" product="Foxy Commerce">
    <div>
        <h2 style="margin:0 0 4px">Em qual loja você vai trabalhar?</h2>
    </div>
    @foreach ($options as $o)
        <form method="POST" action="{{ route('commerce.stores.switch') }}">
            @csrf
            <input type="hidden" name="organization_id" value="{{ $o['membership']->organization_id }}">
            <button type="submit" class="secondary" style="width:100%;text-align:left;">
                <strong>{{ $o['store']?->name ?? $o['membership']->organization->name }}</strong> · {{ $o['role'] }}
            </button>
        </form>
    @endforeach
    <form method="POST" action="{{ route('commerce.logout') }}">
        @csrf
        <button type="submit" class="secondary" style="width:100%;">Sair</button>
    </form>
</x-core::plain>
