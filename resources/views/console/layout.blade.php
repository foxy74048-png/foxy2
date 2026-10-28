<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Foxy AI — Console de testes</title>
    <style>
        :root { --fg:#1f2328; --muted:#656d76; --line:#d0d7de; --bg:#f6f8fa; --accent:#e8590c; --ok:#1a7f37; --err:#cf222e; }
        * { box-sizing: border-box; }
        body { margin:0; font:15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; color:var(--fg); background:var(--bg); }
        header { display:flex; justify-content:space-between; align-items:center; padding:12px 24px; background:#fff; border-bottom:1px solid var(--line); }
        header strong { color:var(--accent); }
        main { max-width:1200px; margin:0 auto; padding:24px; }
        .card { background:#fff; border:1px solid var(--line); border-radius:8px; padding:16px; margin-bottom:16px; }
        label { display:block; font-size:13px; color:var(--muted); margin-bottom:4px; }
        input, select, textarea { width:100%; padding:8px; border:1px solid var(--line); border-radius:6px; font:inherit; }
        textarea { min-height:70px; resize:vertical; }
        button { padding:8px 14px; border:1px solid var(--line); border-radius:6px; background:#fff; font:inherit; cursor:pointer; }
        button.primary { background:var(--accent); border-color:var(--accent); color:#fff; }
        button:disabled { opacity:.6; cursor:wait; }
        .row { display:grid; gap:12px; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); margin-bottom:12px; }
        .muted { color:var(--muted); font-size:13px; }
        .error { color:var(--err); }
        .msg { border-left:3px solid var(--line); padding:8px 12px; margin:12px 0; white-space:pre-wrap; }
        .msg.user { border-color:var(--muted); background:var(--bg); }
        .msg.ai { border-color:var(--accent); }
        details { margin-top:6px; } summary { cursor:pointer; color:var(--muted); font-size:13px; }
        pre { background:var(--bg); padding:8px; border-radius:6px; overflow:auto; font-size:12px; white-space:pre-wrap; }
        .chips button { margin:0 6px 6px 0; font-size:13px; }
        .grid3 { display:grid; gap:12px; grid-template-columns:repeat(auto-fit, minmax(300px, 1fr)); }
        .badge { display:inline-block; padding:1px 8px; border-radius:10px; background:var(--bg); border:1px solid var(--line); font-size:12px; }
    </style>
</head>
<body>
    @yield('body')
</body>
</html>
