{{-- Formulário do comunicado (novo ou rascunho). $a = comunicado ou null. --}}
@php
    $audience = $a?->audience ?? [];
    $val = fn ($field, $default = null) => old($field, $a?->{$field} ?? $default);
@endphp
<form method="POST" action="{{ $a ? route('admin.announcements.update', $a) : route('admin.announcements.store') }}" class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); align-items:start;">
    @csrf
    <div><label for="title">Nome (só para a equipe)</label><input id="title" name="title" required maxlength="160" value="{{ $val('title') }}" placeholder="Ex.: Novidade: a raposa chegou"></div>
    <div>
        <label for="channel">Canal</label>
        <select id="channel" name="channel">
            @foreach (\App\Platform\Announcements\Announcement::CHANNELS as $k => $label)<option value="{{ $k }}" @selected($val('channel', 'email') === $k)>{{ $label }}</option>@endforeach
        </select>
        @unless ($whatsappReady)<div class="helper">WhatsApp da Foxy ainda não configurado no servidor.</div>@endunless
    </div>
    <div style="grid-column: 1 / -1;"><label for="subject">Assunto do e-mail</label><input id="subject" name="subject" maxlength="200" value="{{ $val('subject') }}" placeholder="Ex.: {nome}, a sua assistente de IA chegou ao Foxy Studio"></div>
    <div style="grid-column: 1 / -1;">
        <label for="body">Mensagem</label>
        <textarea id="body" name="body" rows="8" maxlength="4000" required placeholder="Olá, {nome}! ...">{{ $val('body') }}</textarea>
        <div class="helper">Use {nome} (pessoa de contato), {conta} (nome da conta) e {produto}. O link para sair da lista entra sozinho no fim.</div>
    </div>
    <div>
        <label for="integration_id">Produto</label>
        <select id="integration_id" name="integration_id">
            <option value="">Todos</option>
            @foreach ($integrations as $i)<option value="{{ $i->id }}" @selected(old('integration_id', $audience['integration_id'] ?? '') === $i->id)>{{ $i->name }}</option>@endforeach
        </select>
    </div>
    <div>
        <label>Situação das contas</label>
        @foreach (\App\Platform\Products\ProductAccount::STATUSES as $k => $label)
            @continue($k === 'removed')
            <label style="display:flex; gap:6px; font-weight:400;"><input type="checkbox" name="statuses[]" value="{{ $k }}" @checked(in_array($k, old('statuses', $audience['statuses'] ?? []), true))> {{ $label }}</label>
        @endforeach
        <div class="helper">Nenhuma marcada = todas.</div>
    </div>
    <div>
        <label>Planos</label>
        @forelse ($plans as $plan)
            <label style="display:flex; gap:6px; font-weight:400;"><input type="checkbox" name="plans[]" value="{{ $plan }}" @checked(in_array($plan, old('plans', $audience['plans'] ?? []), true))> {{ $plan }}</label>
        @empty
            <div class="muted">Nenhum plano informado pelos produtos ainda.</div>
        @endforelse
        <div class="helper">Nenhum marcado = todos.</div>
    </div>
    <div style="grid-column: 1 / -1;"><button type="submit">{{ $a ? 'Salvar rascunho' : 'Salvar e conferir' }}</button></div>
</form>
