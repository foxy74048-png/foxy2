<x-core::layout title="Equipe" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>
    <x-slot:actions>@include('commerce.export.buttons')@if ($canManage)@include('commerce._add', ['label' => 'Convidar pessoa', 'target' => 'convidar'])@endif</x-slot:actions>

    @include('commerce.status')

    @if ($canManage)
        <form class="card form-grid" id="convidar" method="POST" action="{{ route('commerce.team.invite') }}" style="align-items:end;">
            @csrf
            <div style="grid-column: 1 / -1;">
                <h3 style="margin:0;">Convidar alguém</h3>
                <p class="helper" style="margin:4px 0 0;">Cada pessoa recebe um convite por e-mail e cria o próprio login.</p>
            </div>
            <div>
                <label for="email">E-mail</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required>
            </div>
            <div>
                <label for="role">Papel</label>
                <select id="role" name="role">
                    @foreach ($roles as $key => $label)<option value="{{ $key }}" @selected(old('role', 'attendant') === $key)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <div><button type="submit">Enviar convite</button></div>
        </form>

        <div class="card" style="margin-top:16px;">
            <h3 style="margin-top:0;">O que cada papel faz</h3>
            <ul style="margin:0;padding-left:20px;line-height:1.8;">
                <li><strong>Dono(a):</strong> tudo, inclusive assinatura e equipe.</li>
                <li><strong>Gerente:</strong> produtos, anúncios, estoque, pedidos, canais e relatórios.</li>
                <li><strong>Atendente:</strong> registra pedidos, consulta estoque e cuida de separar e enviar.</li>
            </ul>
        </div>
    @endif

    <div class="card" style="margin-top:16px;">
        <h3 style="margin-top:0;">Pessoas na equipe</h3>
        <div class="table-wrap"><table>
            <thead><tr><th>Nome</th><th>E-mail</th><th>Papel</th>@if ($canManage)<th></th>@endif</tr></thead>
            <tbody>
                @foreach ($members as $m)
                    <tr>
                        <td><strong>{{ $m->user->name }}</strong>@if ($m->user_id === auth()->id()) <span class="badge">você</span>@endif @if ($m->status === 'suspended') <span class="badge danger">suspenso</span>@endif</td>
                        <td>{{ $m->user->email }}</td>
                        <td>
                            @if ($canManage)
                                <form method="POST" action="{{ route('commerce.team.role', $m->id) }}" style="display:flex;gap:8px;">
                                    @csrf
                                    <select name="role" aria-label="Papel de {{ $m->user->name }}">
                                        @foreach ($roles as $key => $label)<option value="{{ $key }}" @selected($m->role === $key)>{{ $label }}</option>@endforeach
                                    </select>
                                    <button type="submit" class="secondary">Salvar</button>
                                </form>
                            @else
                                {{ $roles[$m->role] ?? $m->role }}
                            @endif
                        </td>
                        @if ($canManage)
                            <td style="display:flex;gap:8px;flex-wrap:wrap;">
                                @if ($m->user_id !== auth()->id())
                                    <form method="POST" action="{{ route('commerce.team.suspend', $m->id) }}">
                                        @csrf<input type="hidden" name="suspend" value="{{ $m->status === 'suspended' ? 0 : 1 }}">
                                        <button type="submit" class="secondary">{{ $m->status === 'suspended' ? 'Reativar' : 'Suspender' }}</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('commerce.team.remove', $m->id) }}" onsubmit="return confirm('Retirar o acesso desta pessoa à loja?');">
                                    @csrf
                                    <button type="submit" class="danger">Retirar acesso</button>
                                </form>
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table></div>
    </div>

    @if ($canManage && $invitations->isNotEmpty())
        <div class="card" style="margin-top:16px;">
            <h3 style="margin-top:0;">Convites esperando resposta</h3>
            <div class="table-wrap"><table>
                <thead><tr><th>E-mail</th><th>Papel</th><th>Vence em</th><th></th></tr></thead>
                <tbody>
                    @foreach ($invitations as $i)
                        <tr>
                            <td>{{ $i->email }}</td>
                            <td>{{ $roles[$i->role] ?? $i->role }}</td>
                            <td>{{ $i->expires_at->format('d/m/Y') }}</td>
                            <td>
                                <form method="POST" action="{{ route('commerce.team.invitations.cancel', $i->id) }}">
                                    @csrf
                                    <button type="submit" class="secondary">Cancelar</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        </div>
    @endif
</x-core::layout>
