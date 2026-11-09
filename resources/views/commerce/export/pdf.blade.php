<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
    @page { margin: 28px 30px 44px 30px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #1f1b2d; }
    .head { border-bottom: 2px solid #5b3fd1; padding-bottom: 8px; margin-bottom: 10px; }
    .brand { color: #5b3fd1; font-size: 8px; letter-spacing: 1.5px; text-transform: uppercase; }
    h1 { font-size: 16px; margin: 2px 0 0; }
    .meta { color: #6b6880; margin-top: 3px; }
    .filters { margin: 0 0 10px; color: #4a4660; }
    .filters span { display: inline-block; margin-right: 12px; }
    h2 { font-size: 11px; margin: 14px 0 6px; color: #4527a0; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #f1eefb; color: #4a4660; text-align: left; font-size: 8px; text-transform: uppercase; letter-spacing: .4px; padding: 5px 6px; border-bottom: 1px solid #d9d3f3; }
    td { padding: 4px 6px; border-bottom: 1px solid #ecebf2; vertical-align: top; }
    tr:nth-child(even) td { background: #faf9fd; }
    .num { text-align: right; white-space: nowrap; }
    .empty { color: #6b6880; padding: 8px 0; }
    .note { margin-top: 10px; color: #8a4b08; }
    .foot { position: fixed; bottom: -30px; left: 0; color: #8a879b; font-size: 8px; }
</style>
</head>
<body>
    <div class="foot">Foxy Commerce · {{ $store }} · gerado em {{ $generatedAt->format('d/m/Y H:i') }}</div>
    <div class="head">
        <div class="brand">Foxy Commerce{{ $store ? ' · '.$store : '' }}</div>
        <h1>{{ $title }}</h1>
        <div class="meta">Gerado em {{ $generatedAt->format('d/m/Y \à\s H:i') }}</div>
    </div>
    @if ($filters)
        <div class="filters">@foreach ($filters as $label => $value)<span><strong>{{ $label }}:</strong> {{ $value }}</span>@endforeach</div>
    @endif

    @foreach ($sections as $section)
        @if ($section['title'])<h2>{{ $section['title'] }}</h2>@endif
        @if ($section['cells'] === [])
            <div class="empty">Nada para mostrar com esses filtros.</div>
        @else
            <table>
                <thead><tr>@foreach ($section['columns'] as $label => $type)<th @class(['num' => $type !== 'text' && $type !== 'date' && $type !== 'datetime'])>{{ $label }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach ($section['cells'] as $row)
                        <tr>@foreach ($row as $i => $cell)<td @class(['num' => ! in_array($section['types'][$i], ['text', 'date', 'datetime'], true)])>{{ $cell }}</td>@endforeach</tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endforeach

    @if ($truncated)
        <p class="note">O PDF mostra até {{ number_format(\App\Commerce\Insights\Export::PDF_MAX_ROWS, 0, ',', '.') }} linhas. Para a lista completa, baixe a planilha.</p>
    @endif
</body>
</html>
