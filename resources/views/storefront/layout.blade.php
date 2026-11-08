@props(['title' => null])
{{-- Loja própria: visual da loja do lojista (cor e logo dele), separado do visual do Core. --}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title.' — ' : '' }}{{ $store->name }}</title>
    <style>
        :root { --brand: {{ $store->color() }}; --ink: #1d1b26; --muted: #6b6a75; --line: #e8e6ef; --bg: #faf9fc; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--ink); background: var(--bg); line-height: 1.5; }
        a { color: var(--brand); }
        .wrap { max-width: 1120px; margin: 0 auto; padding: 0 16px; }
        header.top { background: #fff; border-bottom: 1px solid var(--line); position: sticky; top: 0; z-index: 5; }
        header.top .wrap { display: flex; align-items: center; gap: 16px; height: 68px; }
        .brand { display: flex; align-items: center; gap: 10px; font-weight: 700; font-size: 1.15rem; color: var(--ink); text-decoration: none; }
        .brand img { height: 40px; width: auto; }
        .search { flex: 1; display: flex; }
        .search input { width: 100%; max-width: 420px; }
        .cart-link { margin-left: auto; text-decoration: none; font-weight: 600; color: var(--ink); white-space: nowrap; }
        .cart-link span { background: var(--brand); color: #fff; border-radius: 999px; padding: 1px 8px; font-size: .8rem; margin-left: 4px; }
        input, select, textarea { font: inherit; padding: 10px 12px; border: 1px solid var(--line); border-radius: 10px; background: #fff; width: 100%; }
        label { display: block; font-weight: 600; font-size: .9rem; margin-bottom: 4px; }
        .btn { display: inline-block; background: var(--brand); color: #fff; border: 0; border-radius: 10px; padding: 12px 20px; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; text-align: center; }
        .btn:hover { filter: brightness(.92); }
        .btn.ghost { background: #fff; color: var(--ink); border: 1px solid var(--line); }
        .btn[disabled] { opacity: .5; cursor: not-allowed; }
        main { padding: 24px 0 48px; }
        .chips { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; }
        .chips a { padding: 6px 14px; border: 1px solid var(--line); border-radius: 999px; background: #fff; text-decoration: none; color: var(--ink); font-size: .9rem; }
        .chips a.on { background: var(--brand); border-color: var(--brand); color: #fff; }
        .grid { display: grid; gap: 18px; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); }
        .tile { background: #fff; border: 1px solid var(--line); border-radius: 14px; overflow: hidden; text-decoration: none; color: inherit; display: flex; flex-direction: column; }
        .tile .ph { aspect-ratio: 1; background: #f1eff6; display: flex; align-items: center; justify-content: center; color: var(--muted); }
        .tile .ph img { width: 100%; height: 100%; object-fit: cover; }
        .tile .info { padding: 12px 14px; }
        .price { font-weight: 700; font-size: 1.1rem; }
        .muted { color: var(--muted); font-size: .9rem; }
        .card { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 20px; }
        .alert { padding: 12px 14px; border-radius: 10px; margin-bottom: 16px; }
        .alert.ok { background: #e9f7ef; color: #14532d; } .alert.err { background: #fdecec; color: #8a1c1c; }
        table { width: 100%; border-collapse: collapse; } td, th { padding: 10px 6px; border-bottom: 1px solid var(--line); text-align: left; }
        .two { display: grid; gap: 24px; grid-template-columns: 1fr 1fr; }
        @media (max-width: 760px) { .two { grid-template-columns: 1fr; } .search { display: none; } }
        footer { border-top: 1px solid var(--line); padding: 20px 0; color: var(--muted); font-size: .85rem; background: #fff; }
    </style>
</head>
<body>
<header class="top">
    <div class="wrap">
        <a class="brand" href="{{ route('commerce.storefront.home', $store->slug) }}">@if ($store->logo)<img src="{{ $store->logo->url() }}" alt="">@endif<span>{{ $store->name }}</span></a>
        <form class="search" method="GET" action="{{ route('commerce.storefront.home', $store->slug) }}"><input name="q" value="{{ request('q') }}" placeholder="Buscar produtos" aria-label="Buscar produtos"></form>
        <a class="cart-link" href="{{ route('commerce.storefront.cart', $store->slug) }}">Carrinho<span>{{ $cartCount ?? 0 }}</span></a>
    </div>
</header>
<main>
    <div class="wrap">
        @if (session('status'))<div class="alert ok">{{ session('status') }}</div>@endif
        @if ($errors->any())<div class="alert err">{{ $errors->first() }}</div>@endif
        {{ $slot }}
    </div>
</main>
<footer>
    <div class="wrap">
        <strong>{{ $store->name }}</strong>@if ($store->about) — {{ $store->about }}@endif
        @if ($store->whatsapp)<div>WhatsApp: {{ $store->whatsapp }}</div>@endif
        <div style="margin-top:6px;">Loja feita com Foxy Commerce</div>
    </div>
</footer>
</body>
</html>
