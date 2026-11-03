<x-core::layout :title="$account->name" product="Foxy Admin" subtitle="Administrativo da Foxy">
    <x-slot:nav>@include('admin.nav')</x-slot:nav>
    <x-slot:actions>
        <a class="button secondary" href="{{ route('admin.accounts') }}">← Contas</a>
        @if ($account->admin_url)<a class="button secondary" href="{{ $account->admin_url }}" target="_blank" rel="noopener">Abrir no admin do {{ $account->integration?->name }}</a>@endif
    </x-slot:actions>
    @php
        $n = fn ($v, $d = 0) => number_format((float) $v, $d, ',', '.');
        $tz = 'America/Sao_Paulo';
    @endphp

    @if (session('status'))<div class="alert success">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif

    <div class="grid cards">
        <div class="card">
            <div class="muted">{{ $account->integration?->name }}</div>
            <h2 style="margin:4px 0;">{{ $account->statusLabel() }}</h2>
            <div class="helper">Plano {{ $account->plan ?? '—' }} · MRR R$ {{ $n($account->mrr, 2) }}</div>
            @if ($account->status === 'trial' && $account->trial_ends_at)<div class="helper">Teste até {{ $account->trial_ends_at->format('d/m/Y') }}</div>@endif
        </div>
        <div class="card">
            <div class="muted">Uso do produto</div>
            <h2 style="margin:4px 0;">{{ $account->healthLabel() ?? '—' }}</h2>
            <div class="helper">Cadastro {{ $account->signed_up_at?->format('d/m/Y') ?? '—' }} · última atividade {{ $account->last_activity_at?->format('d/m/Y') ?? '—' }}</div>
            @if ($account->usage)<div class="helper">@foreach ($account->usage as $label => $value){{ $label }}: {{ is_numeric($value) ? $n($value) : $value }}@if (! $loop->last) · @endif @endforeach</div>@endif
        </div>
        <div class="card">
            <div class="muted">Raposa neste mês</div>
            <h2 style="margin:4px 0;">{{ $n($ai['messages'] ?? 0) }} perguntas</h2>
            <div class="helper">{{ $n($ai['credits'] ?? 0, 1) }} créditos · US$ {{ $n($ai['cost_usd'] ?? 0, 4) }} · +{{ $n($extraCredits) }} extras/brindes</div>
            <div class="helper">Liberada à mão: {{ $account->fox_credits ? $n($account->fox_credits).' por mês' : 'não (vale o plano)' }}</div>
        </div>
        <div class="card">
            <div class="muted">Contato do dono</div>
            <h2 style="margin:4px 0; font-size:18px;">{{ $account->contact_name ?? '—' }}</h2>
            <div class="helper">{{ $account->email ?? 'sem e-mail' }}@if ($account->email_opt_out_at) (saiu da lista do e-mail)@endif</div>
            <div class="helper">{{ $account->whatsapp ?? 'sem WhatsApp' }}@if ($account->whatsapp_opt_out_at) (saiu da lista do WhatsApp)@endif</div>
        </div>
    </div>

    @if (! empty($account->problems))
        <div class="card stack" style="margin-top:16px; border-color:#fca5a5;">
            <h3 style="margin:0;">⚠️ Problemas nesta conta</h3>
            @foreach ($account->problems as $problem)
                <div><strong>{{ $problem['label'] }}</strong><div class="helper">{{ $problem['detail'] ?? '' }}</div></div>
            @endforeach
            <form method="POST" action="{{ route('admin.accounts.help', $account) }}" class="stack">
                @csrf
                <label for="help">Mensagem de ajuda (sai pelo WhatsApp da Foxy para o dono)</label>
                <textarea id="help" name="message" rows="4" maxlength="2000">{{ old('message', $account->problems[0]['help'] ?? '') }}</textarea>
                <div><button type="submit" @disabled(! $account->whatsapp || ! $whatsappReady)>Enviar ajuda por WhatsApp</button></div>
            </form>
        </div>
    @endif

    <div class="grid two" style="margin-top:16px;">
        <div class="card stack">
            <h3 style="margin:0;">Ações na conta</h3>
            <div class="helper">Vão para o {{ $account->integration?->name }} executar (em até 1 minuto). O resultado aparece no histórico.</div>

            <form method="POST" action="{{ route('admin.accounts.command', $account) }}" style="display:flex; gap:8px; align-items:end; flex-wrap:wrap;">
                @csrf <input type="hidden" name="type" value="account.extend_trial">
                <div><label for="days">Estender o teste em</label><input id="days" name="days" type="number" min="1" max="365" value="7" style="width:90px;"> dias</div>
                <button class="button secondary" type="submit">Estender teste</button>
            </form>

            <form method="POST" action="{{ route('admin.accounts.command', $account) }}" style="display:flex; gap:8px; align-items:end; flex-wrap:wrap;">
                @csrf <input type="hidden" name="type" value="account.fox_credits">
                <div><label for="credits">Liberar a raposa</label>
                    <select id="credits" name="credits">
                        <option value="0">Tirar liberação (vale o plano)</option>
                        @foreach ($sizes as $size)<option value="{{ $size }}" @selected($account->fox_credits === $size)>{{ $n($size) }} perguntas/mês</option>@endforeach
                    </select></div>
                <button class="button secondary" type="submit">Aplicar</button>
            </form>

            <form method="POST" action="{{ route('admin.accounts.command', $account) }}" style="display:flex; gap:8px;">
                @csrf <input type="hidden" name="type" value="account.founder">
                @if ($account->status === 'founder')
                    <input type="hidden" name="active" value="0"><button class="button secondary" type="submit">Desativar Parceiro Fundador</button>
                @else
                    <input type="hidden" name="active" value="1"><button class="button secondary" type="submit">Tornar Parceiro Fundador</button>
                @endif
            </form>

            @if ($account->status === 'suspended')
                <form method="POST" action="{{ route('admin.accounts.command', $account) }}">@csrf <input type="hidden" name="type" value="account.unsuspend"><button type="submit">Reativar a conta</button></form>
            @else
                <form method="POST" action="{{ route('admin.accounts.command', $account) }}" style="display:flex; gap:8px; align-items:end; flex-wrap:wrap;" onsubmit="return confirm('Suspender a conta? O cliente perde o acesso e a página pública sai do ar (nada é apagado).')">
                    @csrf <input type="hidden" name="type" value="account.suspend">
                    <div><label for="reason">Suspender (motivo)</label><input id="reason" name="reason" maxlength="500" placeholder="opcional"></div>
                    <button class="button danger" type="submit">Suspender</button>
                </form>
            @endif
        </div>

        <div class="card stack">
            <h3 style="margin:0;">🎁 Perguntas de presente</h3>
            <div class="helper">Somam às perguntas da raposa deste mês. Fica no histórico das promoções.</div>
            <form method="POST" action="{{ route('admin.accounts.gift', $account) }}" class="stack">
                @csrf
                <div><label for="gift">Perguntas</label><input id="gift" name="credits" type="number" min="1" max="100000" value="100"></div>
                <div><label for="note">Motivo (opcional)</label><input id="note" name="note" maxlength="300" placeholder="Ex.: mimo da parceria"></div>
                <div><button type="submit">Dar presente</button></div>
            </form>
            @if ($grants->isNotEmpty())
                <div class="helper">Já recebeu: @foreach ($grants as $g){{ $g->promotion?->name }} (+{{ $n($g->credits) }}, {{ $g->period === 'unica' ? 'uma vez' : $g->period }})@if (! $loop->last) · @endif @endforeach</div>
            @endif
        </div>
    </div>

    <div class="grid two" style="margin-top:16px;">
        <div class="card">
            <h3 style="margin-top:0;">Histórico de ações</h3>
            @forelse ($commands as $c)
                <div style="padding:6px 0; border-bottom:1px solid var(--border, #eee);">
                    <strong>{{ $c->describe() }}</strong>
                    <span @class(['badge', 'success' => $c->status === 'feito', 'danger' => $c->status === 'falhou'])>{{ \App\Platform\Products\ProductCommand::STATUSES[$c->status] }}</span>
                    <div class="helper">{{ $c->created_at->timezone($tz)->format('d/m H:i') }}@if ($c->result) · {{ $c->result }}@endif</div>
                </div>
            @empty
                <div class="empty">Nenhuma ação ainda.</div>
            @endforelse
        </div>
        <div class="card">
            <h3 style="margin-top:0;">Comunicados e chamados</h3>
            @foreach ($deliveries as $d)
                <div class="helper">📣 {{ $d->announcement?->title }} — {{ $d->channel === 'email' ? 'e-mail' : 'WhatsApp' }}, {{ \App\Platform\Announcements\AnnouncementDelivery::STATUSES[$d->status] }}</div>
            @endforeach
            @foreach ($tickets as $t)
                <div class="helper">💬 {{ $t->subject ?? 'Chamado' }} — {{ \App\Platform\Products\ProductTicket::STATUSES[$t->status] ?? $t->status }} ({{ $t->opened_at?->timezone($tz)->format('d/m') }}) · <a href="{{ route('admin.support', ['situacao' => 'todos']) }}#t-{{ $t->id }}">ver</a></div>
            @endforeach
            @if ($deliveries->isEmpty() && $tickets->isEmpty())<div class="empty">Nada ainda.</div>@endif
        </div>
    </div>
</x-core::layout>
