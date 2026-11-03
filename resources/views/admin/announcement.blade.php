<x-core::layout :title="$announcement->title" product="Foxy Admin" subtitle="Administrativo da Foxy">
    <x-slot:nav>@include('admin.nav')</x-slot:nav>
    @php
        $channels = $announcement->channels();
        $Ann = \App\Platform\Announcements\Announcement::class;
    @endphp

    @if (session('status'))<div class="alert success">{{ session('status') }}</div>@endif
    @if (session('warning'))<div class="alert error">{{ session('warning') }}</div>@endif
    @if ($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif

    <div class="grid cards">
        <div class="card"><div class="muted">Situação</div><h2>{{ $Ann::STATUSES[$announcement->status] }}</h2><div class="helper">{{ $Ann::CHANNELS[$announcement->channel] }}</div></div>
        <div class="card"><div class="muted">Quem recebe</div><h2>{{ $counts['accounts'] }} conta(s)</h2>
            <div class="helper">@if (in_array('email', $channels, true)){{ $counts['email'] }} por e-mail @endif @if (in_array('whatsapp', $channels, true)) · {{ $counts['whatsapp'] }} por WhatsApp @endif — sem contato ou fora da lista ficam de fora</div></div>
        <div class="card"><div class="muted">Envios</div><h2>{{ $byStatus['enviado'] ?? 0 }} enviados</h2><div class="helper">{{ $byStatus['pendente'] ?? 0 }} na fila · {{ $byStatus['falhou'] ?? 0 }} falharam · {{ $byStatus['pulado'] ?? 0 }} não enviados</div></div>
    </div>

    <div class="grid two" style="margin-top:16px;">
        <div class="card stack">
            <h3 style="margin:0;">Prévia</h3>
            <div class="helper">Como chega para {{ $sample ? '"'.($sample->contact_name ?: $sample->name).'"' : 'uma conta do público' }}.</div>
            @if (in_array('email', $channels, true))<div><strong>Assunto:</strong> {{ $previewSubject }}</div>@endif
            <div style="white-space:pre-wrap; background:var(--bg, #f5f5f8); border-radius:12px; padding:14px;">{{ $preview }}

— Equipe Foxy
(link para sair da lista)</div>
        </div>

        <div class="card stack">
            @if ($announcement->status === 'rascunho')
                <h3 style="margin:0;">1. Teste para você</h3>
                <form method="POST" action="{{ route('admin.announcements.test', $announcement) }}" class="stack">
                    @csrf
                    @if (in_array('email', $channels, true))<div><label for="test_email">Seu e-mail</label><input id="test_email" name="test_email" type="email" value="{{ old('test_email', auth()->user()->email) }}"></div>@endif
                    @if (in_array('whatsapp', $channels, true))<div><label for="test_whatsapp">Seu WhatsApp</label><input id="test_whatsapp" name="test_whatsapp" value="{{ old('test_whatsapp') }}" placeholder="(11) 99999-9999">@unless ($whatsappReady)<div class="helper">WhatsApp da Foxy ainda não configurado no servidor.</div>@endunless</div>@endif
                    <div><button class="button secondary" type="submit">Enviar teste</button></div>
                </form>

                <h3 style="margin:8px 0 0;">2. Enviar para todos</h3>
                <form method="POST" action="{{ route('admin.announcements.send', $announcement) }}" onsubmit="return confirm('Enviar este comunicado para o público? Sai aos poucos e não dá para desfazer o que já saiu.')">
                    @csrf
                    <button type="submit">Enviar para {{ collect($channels)->map(fn ($c) => $counts[$c])->sum() }} mensagem(ns)</button>
                </form>
            @elseif ($announcement->status === 'enviando')
                <h3 style="margin:0;">Enviando</h3>
                <div class="muted">As mensagens saem aos poucos, a cada minuto (o WhatsApp com pausa entre uma e outra). Atualize a página para ver o andamento.</div>
            @else
                <h3 style="margin:0;">{{ $Ann::STATUSES[$announcement->status] }}</h3>
                <div class="muted">{{ $announcement->finished_at ? 'Terminou em '.$announcement->finished_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i').'.' : '' }}</div>
            @endif

            @if (in_array($announcement->status, ['rascunho', 'enviando'], true))
                <form method="POST" action="{{ route('admin.announcements.cancel', $announcement) }}" onsubmit="return confirm('Cancelar? O que já saiu não volta.')">
                    @csrf
                    <button class="button danger" type="submit">Cancelar comunicado</button>
                </form>
            @endif
        </div>
    </div>

    @if ($announcement->status === 'rascunho')
        <details class="card" style="margin-top:16px;">
            <summary><strong>Editar rascunho</strong></summary>
            @include('admin.announcement-form', ['a' => $announcement, 'integrations' => $integrations->values(), 'plans' => \App\Platform\Products\ProductAccount::whereNotNull('plan')->distinct()->orderBy('plan')->pluck('plan')])
        </details>
    @endif

    @if ($deliveries->isNotEmpty())
        <div class="card" style="margin-top:16px;">
            <h3 style="margin-top:0;">Envios</h3>
            <div class="table-wrap"><table>
                <thead><tr><th>Conta</th><th>Canal</th><th>Para</th><th>Situação</th><th>Quando</th></tr></thead>
                <tbody>
                    @foreach ($deliveries as $d)
                        <tr>
                            <td>{{ $d->account?->name }}<div class="helper">{{ $d->account?->integration?->name }}</div></td>
                            <td>{{ $d->channel === 'email' ? 'E-mail' : 'WhatsApp' }}</td>
                            <td>{{ $d->to }}</td>
                            <td><span @class(['badge', 'success' => $d->status === 'enviado', 'danger' => $d->status === 'falhou'])>{{ \App\Platform\Announcements\AnnouncementDelivery::STATUSES[$d->status] }}</span>@if ($d->error)<div class="helper">{{ $d->error }}</div>@endif</td>
                            <td>{{ $d->sent_at?->timezone('America/Sao_Paulo')->format('d/m H:i') ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        </div>
    @endif
</x-core::layout>
