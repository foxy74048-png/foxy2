<x-core::layout title="Marketplaces" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>

    @include('commerce.status')
    @if (session('error'))<div class="alert error" style="margin-bottom:16px;">{{ session('error') }}</div>@endif

    <p class="muted" style="margin-top:0;">Conecte a conta de vendedor de cada marketplace. Depois, crie os anúncios em <a href="{{ route('commerce.listings') }}">Anúncios</a>: o estoque vai sozinho e os pedidos entram em Pedidos.
        @if ($limit !== null) Seu plano permite {{ $limit }} conta(s) conectada(s).@endif</p>

    <div class="grid cards">
        @foreach ($channels as $key => $channel)
            @php($list = $connections->get($key, collect())->where('status', '!=', 'disconnected'))
            <div class="card stack">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
                    <h3 style="margin:0;">{{ $channel['label'] }}</h3>
                    @if (! \App\Commerce\Channels\Channels::available($key))<span class="badge">em breve</span>@elseif ($list->isNotEmpty())<span class="badge success">conectado</span>@endif
                </div>
                @foreach ($list as $c)
                    <div style="border-top:1px solid var(--border);padding-top:10px;">
                        <strong>{{ $c->name ?? $c->external_account_id }}</strong>
                        <span @class(['badge', 'success' => $c->isActive(), 'danger' => ! $c->isActive()])>{{ \App\Commerce\Channels\ChannelConnection::STATUSES[$c->status] ?? $c->status }}</span>
                        <div class="helper">{{ $c->listings_count }} anúncio(s) · última conferência {{ $c->last_synced_at?->diffForHumans() ?? 'ainda não' }}</div>
                        @if ($c->last_error)<div class="alert error" style="margin-top:8px;font-size:.85rem;">{{ $c->last_error }}</div>@endif
                        <form method="POST" action="{{ route('commerce.channels.disconnect', $c) }}" style="margin-top:8px;" onsubmit="return confirm('Desconectar esta conta? Os anúncios ficam pausados no Foxy.');">
                            @csrf<button type="submit" class="secondary">Desconectar</button>
                        </form>
                    </div>
                @endforeach
                @if (\App\Commerce\Channels\Channels::available($key))
                    <form method="POST" action="{{ route('commerce.channels.connect', $key) }}">
                        @csrf<button type="submit" @class(['secondary' => $list->isNotEmpty()])>{{ $list->isEmpty() ? 'Conectar '.$channel['label'] : 'Conectar outra conta' }}</button>
                    </form>
                @else
                    <p class="helper" style="margin:0;">A ligação com o {{ $channel['label'] }} está sendo construída.</p>
                @endif
            </div>
        @endforeach
    </div>
</x-core::layout>
