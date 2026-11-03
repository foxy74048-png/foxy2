<x-core::layout title="Contas" product="Foxy Admin" subtitle="Administrativo da Foxy">
    <x-slot:nav>@include('admin.nav')</x-slot:nav>

    <form class="card form-grid" method="GET" action="{{ route('admin.accounts') }}" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); align-items:end;">
        <div>
            <label for="produto">Produto</label>
            <select id="produto" name="produto">
                <option value="">Todos</option>
                @foreach ($integrations as $i)<option value="{{ $i->id }}" @selected(($filters['produto'] ?? '') === $i->id)>{{ $i->name }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="situacao">Situação</label>
            <select id="situacao" name="situacao">
                <option value="">Todas (menos removidas)</option>
                @foreach (\App\Platform\Products\ProductAccount::STATUSES as $k => $label)<option value="{{ $k }}" @selected(($filters['situacao'] ?? '') === $k)>{{ $label }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="busca">Nome</label>
            <input id="busca" name="busca" value="{{ $filters['busca'] ?? '' }}" placeholder="Buscar conta">
        </div>
        <div><button type="submit">Filtrar</button></div>
    </form>

    <div class="card" style="margin-top:16px;">
        @if ($accounts->isEmpty())
            <div class="empty">Nenhuma conta. Os produtos enviam as contas sozinhos (no Foxy Studio, a cada hora).</div>
        @else
            <div class="table-wrap"><table>
                <thead><tr><th>Conta</th><th>Produto</th><th>Plano</th><th>Situação</th><th>MRR</th><th>Cadastro</th><th>Última atividade</th><th>Raposa (créditos no mês)</th></tr></thead>
                <tbody>
                    @foreach ($accounts as $a)
                        <tr>
                            <td><a href="{{ route('admin.accounts.show', $a) }}"><strong>{{ $a->name }}</strong></a>@if (! empty($a->problems))<div class="helper" style="color:#b91c1c;">⚠️ {{ collect($a->problems)->pluck('label')->implode(' · ') }}</div>@endif @if ($a->healthLabel())<div class="helper">{{ $a->healthLabel() }}</div>@endif</td>
                            <td>{{ $a->integration?->name }}</td>
                            <td>{{ $a->plan ?? '—' }}</td>
                            <td><span @class(['badge', 'success' => $a->status === 'active', 'danger' => in_array($a->status, ['canceled', 'expired', 'suspended'], true)])>{{ $a->statusLabel() }}</span>@if ($a->status === 'trial' && $a->trial_ends_at)<div class="helper">até {{ $a->trial_ends_at->format('d/m') }}</div>@endif</td>
                            <td>R$ {{ number_format((float) $a->mrr, 2, ',', '.') }}</td>
                            <td>{{ $a->signed_up_at?->format('d/m/Y') ?? '—' }}</td>
                            <td>{{ $a->last_activity_at?->format('d/m/Y') ?? '—' }}</td>
                            <td>{{ $aiCredits[$a->id] === null ? 'não usa' : number_format((float) $aiCredits[$a->id], 1, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            <div style="margin-top:12px;">{{ $accounts->links() }}</div>
        @endif
    </div>
</x-core::layout>
