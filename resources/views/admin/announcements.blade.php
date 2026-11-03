<x-core::layout title="Comunicados" product="Foxy Admin" subtitle="Administrativo da Foxy">
    <x-slot:nav>@include('admin.nav')</x-slot:nav>

    @if (session('status'))<div class="alert success">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif

    <div class="card stack">
        <h3 style="margin:0;">Novo comunicado</h3>
        <div class="muted">
            Novidades e melhorias para os clientes dos produtos, por e-mail e/ou pelo WhatsApp da Foxy. Vai para o dono de cada conta,
            sai aos poucos e cada mensagem traz o link para sair da lista. Saíram da lista até hoje: {{ $optOuts['email'] }} do e-mail, {{ $optOuts['whatsapp'] }} do WhatsApp.
        </div>
        @include('admin.announcement-form', ['a' => null])
    </div>

    <div class="card" style="margin-top:16px;">
        <h3 style="margin-top:0;">Comunicados</h3>
        @if ($announcements->isEmpty())
            <div class="empty">Nenhum comunicado ainda.</div>
        @else
            <div class="table-wrap"><table>
                <thead><tr><th>Comunicado</th><th>Canal</th><th>Situação</th><th>Envios</th><th>Criado</th></tr></thead>
                <tbody>
                    @foreach ($announcements as $a)
                        <tr>
                            <td><a href="{{ route('admin.announcements.show', $a) }}"><strong>{{ $a->title }}</strong></a></td>
                            <td>{{ \App\Platform\Announcements\Announcement::CHANNELS[$a->channel] }}</td>
                            <td><span @class(['badge', 'success' => $a->status === 'enviado'])>{{ \App\Platform\Announcements\Announcement::STATUSES[$a->status] }}</span></td>
                            <td>{{ $a->sent_count }} enviados @if ($a->pending_count) · {{ $a->pending_count }} na fila @endif @if ($a->failed_count) · {{ $a->failed_count }} falharam @endif</td>
                            <td>{{ $a->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </div>
</x-core::layout>
