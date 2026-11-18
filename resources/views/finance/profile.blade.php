<x-core::layout title="Meu perfil" product="Foxy Finanças" :subtitle="$currentWallet->name">
    <x-slot:nav>@include('finance.nav')</x-slot:nav>

    @include('finance.status')

    <div class="grid two" style="align-items:start;">
        <form class="card stack" method="POST" action="{{ route('finance.profile.update') }}">
            @csrf
            <h3 style="margin:0;">Seus dados</h3>
            <div><label for="name">Nome</label><input id="name" name="name" value="{{ old('name', $user->name) }}" required maxlength="120" autocomplete="name"></div>
            <div>
                <label for="email">E-mail (é o seu login em todos os produtos Foxy)</label>
                <input id="email" type="email" name="email" value="{{ old('email', $user->email) }}" required maxlength="255" autocomplete="email">
                <div class="helper">Trocou o e-mail? Enviamos um link para confirmar o novo endereço, e o antigo recebe um aviso.</div>
            </div>
            <div data-email-password @unless ($errors->has('email_password')) style="display:none;" @endunless>
                <label for="email_password">Senha atual (para trocar o e-mail)</label>
                <input id="email_password" type="password" name="email_password" autocomplete="current-password">
            </div>
            <div><button type="submit">Salvar</button></div>
            <script>
                (function () {
                    const email = document.getElementById('email'), box = document.querySelector('[data-email-password]'), original = @json($user->email);
                    email.addEventListener('input', function () { box.style.display = email.value.trim().toLowerCase() !== original.toLowerCase() ? '' : 'none'; });
                })();
            </script>
        </form>

        <form class="card stack" method="POST" action="{{ route('finance.profile.password') }}">
            @csrf
            <h3 style="margin:0;">Trocar a senha</h3>
            <div><label for="current_password">Senha atual</label><input id="current_password" type="password" name="current_password" required autocomplete="current-password"></div>
            <div><label for="password">Senha nova (mínimo 8 caracteres)</label><input id="password" type="password" name="password" required minlength="8" autocomplete="new-password"></div>
            <div><label for="password_confirmation">Repita a senha nova</label><input id="password_confirmation" type="password" name="password_confirmation" required minlength="8" autocomplete="new-password"></div>
            <div class="helper">Ao trocar, as outras sessões abertas (em outros aparelhos) são encerradas e você recebe um aviso por e-mail. Esqueceu a atual? Saia e use "Esqueci a senha" na tela de entrar.</div>
            <div><button type="submit">Trocar a senha</button></div>
        </form>
    </div>

    <div class="card" style="margin-top:16px;">
        <h3 style="margin-top:0;">Suas carteiras</h3>
        <div class="table-wrap"><table>
            <thead><tr><th>Carteira</th><th>Tipo</th><th>Seu papel</th></tr></thead>
            <tbody>@foreach ($wallets as $w)<tr><td>{{ $w['wallet']?->name }}</td><td>{{ $w['wallet']?->kindLabel() }}</td><td>{{ $w['role'] }}</td></tr>@endforeach</tbody>
        </table></div>
        <p class="helper" style="margin-bottom:0;">O nome e o tipo de cada carteira ficam em <b>Ajustes da carteira</b>, para quem é dono(a).</p>
    </div>
</x-core::layout>
