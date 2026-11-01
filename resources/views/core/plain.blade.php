@props(['title' => '', 'product' => 'Foxy'])
{{-- Tela simples centralizada (login, avisos), no visual do Core. --}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title.' — ' : '' }}{{ $product }}</title>
    <link rel="icon" href="/favicon.ico">
    <link rel="stylesheet" href="/core/foxy-core.css">
</head>
<body>
<main class="core-plain">
    <div class="card stack">
        <div class="core-brand" style="margin-bottom:4px">
            <img src="/core/brand/logo.png" alt="" onerror="this.remove()" style="width:64px;height:64px">
            <strong style="color:var(--sidebar)">{{ $product }}</strong>
        </div>
        {{ $slot }}
    </div>
</main>
</body>
</html>
