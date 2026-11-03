<x-core::plain title="Comunicados da Foxy">
    @php $canal = $delivery->channel === 'email' ? 'e-mail' : 'WhatsApp'; @endphp
    <div class="stack" style="text-align:center;">
        @if ($done)
            <h2>Pronto</h2>
            <p class="muted">Você não vai mais receber comunicados da Foxy por {{ $canal }}. Avisos importantes da sua conta (pagamento, segurança) continuam chegando.</p>
        @else
            <h2>Não quer receber comunicados por {{ $canal }}?</h2>
            <p class="muted">São novidades e melhorias dos produtos da Foxy. Avisos importantes da sua conta (pagamento, segurança) continuam chegando.</p>
            <form method="POST" action="{{ request()->fullUrl() }}">
                @csrf
                <button type="submit">Sim, não quero receber por {{ $canal }}</button>
            </form>
        @endif
    </div>
</x-core::plain>
