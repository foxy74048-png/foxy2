<x-core::layout title="Suporte" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>

    @include('commerce.status')

    <p class="muted" style="margin-top:0;">Dúvida rápida? Pergunte à raposa (botão no canto da tela). Para falar com a equipe Foxy, abra um chamado.</p>

    <form class="card form-grid" method="POST" action="{{ route('commerce.support.store') }}">
        @csrf
        <div style="grid-column: 1 / -1;"><h3 style="margin:0;">Novo chamado</h3></div>
        <div><label for="subject">Assunto</label><input id="subject" name="subject" value="{{ old('subject') }}" required maxlength="150"></div>
        <div>
            <label for="category">Tipo</label>
            <select id="category" name="category">@foreach (\App\Commerce\Http\SupportController::CATEGORIES as $k => $l)<option value="{{ $k }}" @selected(old('category') === $k)>{{ $l }}</option>@endforeach</select>
        </div>
        <div style="grid-column: 1 / -1;"><label for="message">Conte o que aconteceu</label><textarea id="message" name="message" rows="5" required maxlength="5000" placeholder="Em qual tela, o que você fez e o que apareceu.">{{ old('message') }}</textarea></div>
        <div><button type="submit">Abrir chamado</button></div>
    </form>

    <div class="card" style="margin-top:16px;">
        <h3 style="margin-top:0;">Seus chamados</h3>
        @forelse ($tickets as $t)
            <div style="border-top:1px solid var(--border);padding:12px 0;">
                <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;">
                    <strong>{{ $t->subject }}</strong>
                    <span @class(['badge', 'success' => $t->status === 'answered', 'danger' => $t->status === 'open'])>{{ \App\Platform\Products\ProductTicket::STATUSES[$t->status] ?? $t->status }}</span>
                </div>
                <div class="helper">{{ $t->category }} · aberto em {{ $t->opened_at?->format('d/m/Y H:i') }}</div>
                <p style="white-space:pre-line;margin:8px 0;">{{ \Illuminate\Support\Str::after($t->message, "\n\n") }}</p>
                @if ($t->admin_response)
                    <div class="alert info" style="white-space:pre-line;"><strong>Equipe Foxy ({{ $t->responded_at?->format('d/m/Y H:i') }}):</strong>
{{ $t->admin_response }}</div>
                @endif
            </div>
        @empty
            <div class="empty">Nenhum chamado ainda.</div>
        @endforelse
    </div>
</x-core::layout>
