@extends('console.layout')

@section('body')
<main style="max-width:380px; margin-top:10vh">
    <div class="card">
        <h2 style="margin-top:0"><strong style="color:var(--accent)">Foxy AI</strong> — Console de testes</h2>
        <p class="muted">Acesso da equipe Foxy.</p>
        <form method="POST" action="/console/login">
            @csrf
            <p><label for="email">E-mail</label><input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus></p>
            <p><label for="password">Senha</label><input id="password" name="password" type="password" required></p>
            @error('email')<p class="error">{{ $message }}</p>@enderror
            <button class="primary" type="submit">Entrar</button>
        </form>
    </div>
</main>
@endsection
