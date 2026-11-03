<x-core::layout title="Ideias e dúvidas" product="Foxy Admin" subtitle="Administrativo da Foxy">
    <x-slot:nav>@include('admin.nav')</x-slot:nav>

    @if (session('status'))<div class="alert success">{{ session('status') }}</div>@endif

    <div class="card stack">
        <div class="muted">
            O que a raposa anotou nas conversas, em todos os produtos — só o resumo, sem nomes de clientes.
            <b>Ideias:</b> pediram algo que o produto ainda não faz. <b>Dúvidas sem resposta:</b> o manual não explica — complete o manual do produto e publique de novo.
        </div>
        <div class="toolbar" style="justify-content:flex-start; flex-wrap:wrap; gap:8px;">
            @foreach (\App\Ai\Insights\AiInsight::KINDS as $k => $label)
                <a href="{{ route('admin.insights', ['tipo' => $k]) }}" @class(['button', 'secondary' => $kind !== $k])>{{ $label }} ({{ $newByKind[$k] ?? 0 }} novas)</a>
            @endforeach
        </div>
        <div class="toolbar" style="justify-content:flex-start; flex-wrap:wrap; gap:8px;">
            @foreach (['nova' => 'Novas'] + array_diff_key(\App\Ai\Insights\AiInsight::STATUSES, ['nova' => 1]) + ['todas' => 'Todas'] as $k => $label)
                <a href="{{ route('admin.insights', ['tipo' => $kind, 'situacao' => $k]) }}" @class(['button', 'secondary' => $status !== $k])>{{ $label }}@if ($k !== 'todas') ({{ $counts[$k] ?? 0 }})@endif</a>
            @endforeach
        </div>
    </div>

    <div class="card" style="margin-top:16px;">
        @if ($insights->isEmpty())
            <div class="empty">Nada {{ $status === 'todas' ? 'anotado ainda' : 'nesta situação' }}.</div>
        @else
            <div class="table-wrap"><table>
                <thead><tr><th>Quando</th><th>O que pediram</th><th>Produto e conta</th><th>Situação</th></tr></thead>
                <tbody>
                    @foreach ($insights as $item)
                        @php $account = $accountOf($item->organization); @endphp
                        <tr>
                            <td>{{ $item->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</td>
                            <td><strong>{{ $item->summary }}</strong></td>
                            <td>{{ $item->organization?->integration?->name ?? '—' }}<div class="helper">{{ $account?->name ?? $item->organization?->name }}</div></td>
                            <td>
                                <form method="POST" action="{{ route('admin.insights.update', $item) }}" style="display:flex; gap:6px; align-items:center;">
                                    @csrf
                                    <select name="status" aria-label="Situação">
                                        @foreach (\App\Ai\Insights\AiInsight::STATUSES as $k => $label)<option value="{{ $k }}" @selected($item->status === $k)>{{ $label }}</option>@endforeach
                                    </select>
                                    <button class="button secondary" type="submit">Salvar</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            <div style="margin-top:12px;">{{ $insights->links() }}</div>
        @endif
    </div>
</x-core::layout>
