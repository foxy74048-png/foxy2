@props([
    'title' => '',
    'product' => 'Foxy',
    'subtitle' => null,
])
{{-- Estrutura padrão dos produtos Foxy: menu lateral escuro + conteúdo. Slots: nav, actions, head, scripts. --}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' — ' : '' }}{{ $product }}</title>
    <link rel="icon" href="/favicon.ico">
    <link rel="stylesheet" href="/core/foxy-core.css">
    {{ $head ?? '' }}
</head>
<body>
<div class="core-shell">
    <button type="button" class="core-menu-btn" aria-label="Abrir menu" aria-controls="core-sidebar" aria-expanded="false">
        <x-core::icon name="menu" />
    </button>
    <div class="core-backdrop"></div>

    <aside class="core-sidebar" id="core-sidebar">
        <div class="core-brand">
            <img src="/core/brand/logo.png" alt="" onerror="this.remove()">
            <strong>{{ $product }}</strong>
            @if ($subtitle)<small>{{ $subtitle }}</small>@endif
        </div>
        <nav class="core-nav" aria-label="Menu principal">
            {{ $nav ?? '' }}
        </nav>
    </aside>

    <main class="core-main">
        <header class="core-topbar">
            <h1>{{ $title }}</h1>
            <div class="toolbar">{{ $actions ?? '' }}</div>
        </header>
        <div class="core-content">
            {{ $slot }}
        </div>
    </main>
</div>
<script>
(() => {
    const btn = document.querySelector('.core-menu-btn');
    const bar = document.getElementById('core-sidebar');
    const back = document.querySelector('.core-backdrop');
    const toggle = (open) => { bar.classList.toggle('open', open); back.classList.toggle('visible', open); btn.setAttribute('aria-expanded', open); };
    btn.addEventListener('click', () => toggle(!bar.classList.contains('open')));
    back.addEventListener('click', () => toggle(false));
})();
</script>
{{ $scripts ?? '' }}
</body>
</html>
