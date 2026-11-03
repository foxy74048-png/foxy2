<x-core::layout title="Promoções de IA" product="Foxy Admin" subtitle="Administrativo da Foxy">
    <x-slot:nav>@include('admin.nav')</x-slot:nav>
    @php $n = fn ($v) => number_format((int) $v, 0, ',', '.'); @endphp

    @if (session('status'))<div class="alert success">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif

    <div class="card stack">
        <h3 style="margin:0;">Nova promoção</h3>
        <div class="muted">Perguntas extras de brinde para a raposa. Valem no mês em que entram (as mensais renovam todo mês) e só são usadas por contas que têm a raposa ligada.</div>

        <form method="POST" action="{{ route('admin.promotions.store') }}" class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); align-items:start;">
            @csrf
            <div><label for="name">Nome</label><input id="name" name="name" required maxlength="120" value="{{ old('name') }}" placeholder="Ex.: Boas-vindas: 1º mês +100"></div>
            <div>
                <label for="integration_id">Produto</label>
                <select id="integration_id" name="integration_id">
                    <option value="">Todos</option>
                    @foreach ($integrations as $i)<option value="{{ $i->id }}" @selected(old('integration_id') === $i->id)>{{ $i->name }}</option>@endforeach
                </select>
            </div>
            <div>
                <label for="audience">Quem ganha</label>
                <select id="audience" name="audience" onchange="document.querySelectorAll('[data-aud]').forEach(e => e.hidden = e.dataset.aud !== this.value)">
                    @foreach (\App\Ai\Promotions\AiPromotion::AUDIENCES as $k => $label)<option value="{{ $k }}" @selected(old('audience', 'novas_contas') === $k)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <div>
                <label for="recurrence">Quantas vezes</label>
                <select id="recurrence" name="recurrence">
                    @foreach (\App\Ai\Promotions\AiPromotion::RECURRENCES as $k => $label)<option value="{{ $k }}" @selected(old('recurrence', 'uma_vez') === $k)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <div><label for="credits">Perguntas de brinde</label><input id="credits" name="credits" type="number" min="1" max="100000" required value="{{ old('credits', 100) }}"></div>
            <div data-aud="novas_contas" @if (old('audience', 'novas_contas') !== 'novas_contas') hidden @endif>
                <label for="new_account_days">Conta nova = cadastrada há até</label>
                <input id="new_account_days" name="new_account_days" type="number" min="1" max="365" value="{{ old('new_account_days', 30) }}"><div class="helper">dias</div>
            </div>
            <div><label for="plans">Só nestes planos (opcional)</label><input id="plans" name="plans" value="{{ old('plans') }}" placeholder="ex.: Essencial, Crescimento"><div class="helper">nomes separados por vírgula; vazio = todos</div></div>
            <div><label for="starts_at">Começa em</label><input id="starts_at" name="starts_at" type="date" value="{{ old('starts_at', now()->toDateString()) }}"></div>
            <div><label for="ends_at">Termina em (opcional)</label><input id="ends_at" name="ends_at" type="date" value="{{ old('ends_at') }}"></div>
            <div data-aud="contas" style="grid-column: 1 / -1;" @if (old('audience') !== 'contas') hidden @endif>
                <label for="account_refs">Contas que ganham</label>
                <select id="account_refs" name="account_refs[]" multiple size="8" style="width:100%;">
                    @foreach ($accounts as $a)<option value="{{ $a->external_ref }}" @selected(in_array($a->external_ref, old('account_refs', []), true))>{{ $a->name }} — {{ $a->integration?->name }} ({{ $a->statusLabel() }})</option>@endforeach
                </select>
                <div class="helper">Ctrl (ou Cmd) + clique para escolher várias. Parceria: escolha "Todo mês"; presente avulso: "Uma vez".</div>
            </div>
            <div style="grid-column: 1 / -1;"><label for="note">Anotação (opcional)</label><input id="note" name="note" maxlength="1000" value="{{ old('note') }}" placeholder="Por que esta promoção existe"></div>
            <div><button type="submit">Criar e entregar</button></div>
        </form>
    </div>

    <div class="card" style="margin-top:16px;">
        <h3 style="margin-top:0;">Promoções</h3>
        @if ($promotions->isEmpty())
            <div class="empty">Nenhuma promoção ainda.</div>
        @else
            <div class="table-wrap"><table>
                <thead><tr><th>Promoção</th><th>Quem ganha</th><th>Brinde</th><th>Período</th><th>Entregue</th><th>Situação</th></tr></thead>
                <tbody>
                    @foreach ($promotions as $p)
                        <tr>
                            <td><strong>{{ $p->name }}</strong><div class="helper">{{ $p->integration?->name ?? 'Todos os produtos' }}@if ($p->note) · {{ $p->note }}@endif</div></td>
                            <td>{{ \App\Ai\Promotions\AiPromotion::AUDIENCES[$p->audience] }}@if ($p->audience === 'novas_contas') (até {{ $p->new_account_days }} dias)@endif @if ($p->audience === 'contas')({{ count($p->account_refs ?? []) }})@endif @if ($p->plans)<div class="helper">planos: {{ implode(', ', $p->plans) }}</div>@endif</td>
                            <td>+{{ $n($p->credits) }} · {{ \App\Ai\Promotions\AiPromotion::RECURRENCES[$p->recurrence] }}</td>
                            <td>{{ $p->starts_at?->format('d/m/Y') ?? 'já' }} → {{ $p->ends_at?->format('d/m/Y') ?? 'sem fim' }}</td>
                            <td>{{ $p->grants_count }} entrega(s) · {{ $n($p->grants_sum_credits) }} perguntas</td>
                            <td>
                                <span @class(['badge', 'success' => $p->isRunning()])>{{ $p->isRunning() ? 'Valendo' : \App\Ai\Promotions\AiPromotion::STATUSES[$p->status] }}</span>
                                <form method="POST" action="{{ route('admin.promotions.status', $p) }}" style="display:flex; gap:6px; margin-top:6px;">
                                    @csrf
                                    @if ($p->status === 'ativa')
                                        <button class="button secondary" name="status" value="pausada">Pausar</button>
                                    @elseif ($p->status === 'pausada')
                                        <button class="button secondary" name="status" value="ativa">Retomar</button>
                                    @endif
                                    @if ($p->status !== 'encerrada')
                                        <button class="button danger" name="status" value="encerrada" onclick="return confirm('Encerrar a promoção? O que já foi entregue continua com quem ganhou.')">Encerrar</button>
                                    @endif
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </div>
</x-core::layout>
