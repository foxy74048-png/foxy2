<x-core::layout title="Suporte" product="Foxy Admin" subtitle="Administrativo da Foxy">
    <x-slot:nav>@include('admin.nav')</x-slot:nav>
    @php $tz = 'America/Sao_Paulo'; @endphp

    @if (session('status'))<div class="alert success">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif

    <div class="card stack">
        <div class="muted">Chamados de todos os produtos. A resposta vai para o produto, que grava no chamado e avisa o cliente.</div>
        <div class="toolbar" style="justify-content:flex-start; flex-wrap:wrap; gap:8px;">
            @foreach (\App\Platform\Products\ProductTicket::STATUSES + ['todos' => 'Todos'] as $k => $label)
                <a href="{{ route('admin.support', ['situacao' => $k]) }}" @class(['button', 'secondary' => $status !== $k])>{{ $label }}@if ($k !== 'todos') ({{ $counts[$k] ?? 0 }})@endif</a>
            @endforeach
        </div>
    </div>

    @forelse ($tickets as $t)
        @php $pending = $pendingReplies->get($t->integration_id.'|'.$t->external_id); @endphp
        <div class="card stack" id="t-{{ $t->id }}" style="margin-top:14px;">
            <div class="toolbar">
                <div>
                    <strong>{{ $t->subject ?: 'Chamado' }}</strong>
                    <span @class(['badge', 'danger' => $t->status === 'open', 'success' => $t->status === 'answered'])>{{ \App\Platform\Products\ProductTicket::STATUSES[$t->status] ?? $t->status }}</span>
                    <div class="helper">
                        {{ $t->integration?->name }} ·
                        @if ($t->account)<a href="{{ route('admin.accounts.show', $t->account) }}">{{ $t->account->name }}</a>@else conta não identificada @endif
                        · {{ $t->opened_at?->timezone($tz)->format('d/m/Y H:i') }} @if ($t->category) · {{ $t->category }} @endif
                    </div>
                </div>
            </div>
            <div style="white-space:pre-wrap; background:var(--bg, #f5f5f8); border-radius:12px; padding:12px;">{{ $t->message }}</div>
            @if ($t->admin_response)
                <div class="helper"><strong>Respostas da equipe:</strong></div>
                <div style="white-space:pre-wrap; border-left:3px solid var(--primary, #5b3fd1); padding-left:10px;">{{ $t->admin_response }}</div>
            @endif
            @if ($pending)
                <div class="alert info">Resposta a caminho do produto: "{{ \Illuminate\Support\Str::limit($pending->payload['message'] ?? '', 120) }}"</div>
            @elseif ($t->status !== 'closed')
                <form method="POST" action="{{ route('admin.support.reply', $t) }}" class="stack">
                    @csrf
                    <textarea name="message" rows="3" maxlength="4000" required placeholder="Responder..." aria-label="Resposta"></textarea>
                    <div style="display:flex; gap:8px;">
                        <button type="submit" name="status" value="answered">Responder</button>
                        <button class="button secondary" type="submit" name="status" value="closed">Responder e fechar</button>
                    </div>
                </form>
            @endif
        </div>
    @empty
        <div class="card empty" style="margin-top:14px;">Nenhum chamado {{ $status === 'todos' ? '' : 'nesta situação' }}.</div>
    @endforelse

    <div style="margin-top:12px;">{{ $tickets->links() }}</div>
</x-core::layout>
