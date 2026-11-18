<x-core::layout title="Pessoas na carteira" product="Foxy Finanças" :subtitle="$currentWallet->name">
    <x-slot:nav>@include('finance.nav')</x-slot:nav>
    <x-slot:actions>@include('platform.export-buttons')@if ($canManage)@include('finance._add', ['label' => 'Convidar pessoa', 'target' => 'convidar'])@endif</x-slot:actions>

    @include('finance.status')

    @if ($canManage)
        <form class="card form-grid" id="convidar" method="POST" action="{{ route('finance.people.invite') }}" style="align-items:end;">
            @csrf
            <div style="grid-column: 1 / -1;">
                <h3 style="margin:0;">Convidar alguém</h3>
                <p class="helper" style="margin:4px 0 0;">O parceiro(a), alguém da família ou o sócio recebe um convite por e-mail e cria o próprio login. Até {{ $maxPeople }} pessoas por carteira.</p>
            </div>
            <div>
                <label for="email">E-mail</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required>
            </div>
            <div>
                <label for="role">Papel</label>
                <select id="role" name="role">
                    @foreach ($roles as $key => $label)<option value="{{ $key }}" @selected(old('role', 'member') === $key)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <div><button type="submit">Enviar convite</button></div>
        </form>

        <div class="card" style="margin-top:16px;">
            <h3 style="margin-top:0;">O que cada papel faz</h3>
            <ul style="margin:0;padding-left:20px;line-height:1.8;">
                <li><strong>Dono(a):</strong> tudo, inclusive convidar pessoas e ajustar a carteira.</li>
                <li><strong>Membro:</strong> lança gastos e recebimentos, importa extratos e vê os relatórios.</li>
                <li><strong>Só vê:</strong> acompanha os números, sem mudar nada (ex.: o contador, ou o sócio que só confere).</li>
            </ul>
        </div>
    @endif

    <div class="card" style="margin-top:16px;">
        <h3 style="margin-top:0;">Quem está nesta carteira</h3>
        <div class="table-wrap"><table>
            <thead><tr><th>Nome</th><th>E-mail</th><th>Papel</th>@if ($canManage)<th></th>@endif</tr></thead>
            <tbody>
                @foreach ($members as $m)
                    <tr>
                        <td><strong>{{ $m->user->name }}</strong>@if ($m->user_id === auth()->id()) <span class="badge">você</span>@endif @if ($m->status === 'suspended') <span class="badge danger">suspenso</span>@endif</td>
                        <td>{{ $m->user->email }}</td>
                        <td>
                            @if ($canManage)
                                <form method="POST" action="{{ route('finance.people.role', $m->id) }}" style="display:flex;gap:8px;">
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
                                    <form method="POST" action="{{ route('finance.people.suspend', $m->id) }}">
                                        @csrf<input type="hidden" name="suspend" value="{{ $m->status === 'suspended' ? 0 : 1 }}">
                                        <button type="submit" class="secondary">{{ $m->status === 'suspended' ? 'Reativar' : 'Suspender' }}</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('finance.people.remove', $m->id) }}" onsubmit="return confirm('Retirar o acesso desta pessoa à carteira?');">
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
                                <form method="POST" action="{{ route('finance.people.invitations.cancel', $i->id) }}">
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
