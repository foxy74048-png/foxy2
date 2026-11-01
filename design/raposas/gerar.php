<?php
// Gera 3 estilos x 4 raposas em SVG + uma folha de comparação (HTML).
$out = $argv[1];
$ink = '#221a33';
$purple = '#5b3fd1';
$purpleDeep = '#4527a0';

$foxes = [
    'chico' => ['nome' => 'Chico', 'light' => '#f39a5e', 'mid' => '#e0662a', 'dark' => '#b8481c', 'deep' => '#7e2f10', 'acc' => 'oculos'],
    'lud' => ['nome' => 'Lud', 'light' => '#ffffff', 'mid' => '#eef1f7', 'dark' => '#d3d9e6', 'deep' => '#9aa4ba', 'acc' => 'cachecol'],
    'lobinho' => ['nome' => 'Lobinho', 'light' => '#c3c8d1', 'mid' => '#9aa1ad', 'dark' => '#737a86', 'deep' => '#4a505b', 'acc' => 'fone'],
    'ayla' => ['nome' => 'Ayla', 'light' => '#f5d58c', 'mid' => '#dfb058', 'dark' => '#bb8b33', 'deep' => '#86621f', 'acc' => 'lenco'],
];

function svg($id, $title, $body)
{
    return "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 128 128\" role=\"img\" aria-label=\"$title\">\n<title>$title</title>\n<defs><clipPath id=\"c-$id\"><circle cx=\"64\" cy=\"64\" r=\"62\"/></clipPath></defs>\n$body\n</svg>\n";
}

function poly($pts, $fill, $extra = '')
{
    return '<polygon points="'.implode(' ', array_map(fn ($p) => $p[0].','.$p[1], $pts))."\" fill=\"$fill\" $extra/>";
}

// ---------- A: geométrico (família do logo) ----------
function styleA($id, $f)
{
    global $ink;
    $T1 = [23.5, 9.7]; $E1 = [48.6, 39.7]; $H = [64, 34.6]; $E2 = [79.4, 39.7]; $T2 = [104.5, 9.7];
    $B2 = [103.7, 46]; $C2 = [117.8, 73]; $X = [64, 119]; $C1 = [10.2, 73]; $B1 = [24.3, 46];
    $M = [64, 60]; $Le = [42, 58]; $Re = [86, 58]; $N = [64, 88]; $LM = [36, 84]; $RM = [92, 84];
    $cheek = $id === 'lud' ? '#ffffff' : '#fffaf4';
    $cheek2 = $id === 'lud' ? '#e4e9f2' : '#f3ebe2';
    $facets = [
        [[$T1, $E1, $B1], $f['mid']], [[$T1, [44, 35], [28, 40]], $f['deep']],
        [[$T2, $E2, $B2], $f['dark']], [[$T2, [84, 35], [100, 40]], $f['deep']],
        [[$E1, $H, $M], $f['light']], [[$H, $E2, $M], $f['mid']],
        [[$B1, $E1, $Le], $f['dark']], [[$E1, $M, $Le], $f['mid']],
        [[$B2, $E2, $Re], $f['mid']], [[$E2, $M, $Re], $f['dark']],
        [[$B1, $Le, $C1], $f['mid']], [[$C1, $Le, $LM], $f['light']], [[$Le, $M, $LM], $f['dark']], [[$M, $N, $LM], $f['mid']],
        [[$B2, $Re, $C2], $f['dark']], [[$C2, $Re, $RM], $f['mid']], [[$Re, $M, $RM], $f['mid']], [[$M, $N, $RM], $f['dark']],
        [[$C1, $LM, $X], $cheek], [[$LM, $N, $X], $cheek2], [[$C2, $RM, $X], $cheek2], [[$RM, $N, $X], $cheek],
    ];
    $g = '';
    foreach ($facets as [$p, $c]) {
        $g .= poly($p, $c, 'stroke="'.$c.'" stroke-width="0.6" stroke-linejoin="round"');
    }
    $g .= poly([$T1, $E1, $H, $E2, $T2, $B2, $C2, $X, $C1, $B1], 'none', "stroke=\"$ink\" stroke-width=\"4.5\" stroke-linejoin=\"round\"");
    $g .= poly([[57, 84], [71, 84], [64, 93]], $ink, 'stroke-linejoin="round"');
    foreach ([[44, 62], [84, 62]] as [$x, $y]) {
        $g .= '<path d="M'.($x - 5)." $y Q$x ".($y - 6).' '.($x + 5)." $y Q$x ".($y + 4).' '.($x - 5)." $y Z\" fill=\"$ink\"/>";
        $g .= '<circle cx="'.($x + 1.5).'" cy="'.($y - 1.5).'" r="1.3" fill="#fff"/>';
    }
    $g .= '<path d="M28 64 l1.6 4.4 4.4 1.6 -4.4 1.6 -1.6 4.4 -1.6 -4.4 -4.4 -1.6 4.4 -1.6z" fill="#fff" opacity="0.9"/>';
    $g .= accA($f['acc']);

    return "<circle cx=\"64\" cy=\"64\" r=\"62\" fill=\"#f5f5f8\" stroke=\"#e2dfeb\" stroke-width=\"2\"/>\n<g clip-path=\"url(#c-$id)\"><g transform=\"translate(14 9) scale(0.78)\">$g</g></g>";
}

function accA($a)
{
    global $ink, $purple, $purpleDeep;

    return match ($a) {
        'oculos' => "<g fill=\"rgba(255,255,255,0.18)\" stroke=\"$ink\" stroke-width=\"3.6\" stroke-linejoin=\"round\"><polygon points=\"30,54 36,50 52,50 56,56 52,68 36,68\"/><polygon points=\"98,54 92,50 76,50 72,56 76,68 92,68\"/></g><path d=\"M56 57 L72 57\" stroke=\"$ink\" stroke-width=\"3.6\"/>",
        'cachecol' => "<polygon points=\"20,104 64,126 108,104 112,118 64,140 16,118\" fill=\"$purple\" stroke=\"$ink\" stroke-width=\"3.5\" stroke-linejoin=\"round\"/><polygon points=\"80,128 92,152 106,146 94,122\" fill=\"$purpleDeep\" stroke=\"$ink\" stroke-width=\"3.5\" stroke-linejoin=\"round\"/>",
        'fone' => "<path d=\"M10 74 L14 30 L40 6 L88 6 L114 30 L118 74\" fill=\"none\" stroke=\"$ink\" stroke-width=\"7\" stroke-linejoin=\"round\"/><polygon points=\"0,60 18,56 22,88 4,92\" fill=\"$ink\"/><polygon points=\"128,60 110,56 106,88 124,92\" fill=\"$ink\"/><polygon points=\"6,64 14,62 16,84 8,86\" fill=\"$purple\"/><polygon points=\"122,64 114,62 112,84 120,86\" fill=\"$purple\"/>",
        'lenco' => "<polygon points=\"36,110 92,110 64,144\" fill=\"$purple\" stroke=\"$ink\" stroke-width=\"3.5\" stroke-linejoin=\"round\"/><polygon points=\"56,108 72,108 68,120 60,120\" fill=\"$purpleDeep\" stroke=\"$ink\" stroke-width=\"3\" stroke-linejoin=\"round\"/>",
    };
}

// ---------- B: arredondado amigável ----------
function styleB($id, $f)
{
    global $ink, $purple, $purpleDeep;
    $ear = "<path d=\"M30 60 C25 42 26 22 34 11 C47 17 57 30 61 42 Z\" fill=\"{$f['mid']}\" stroke=\"$ink\" stroke-width=\"2.2\" stroke-linejoin=\"round\"/><path d=\"M35 50 C33 38 34 27 38 20 C45 26 51 33 54 41 Z\" fill=\"{$f['deep']}\"/>";
    $g = $ear."<g transform=\"translate(128 0) scale(-1 1)\">$ear</g>";
    $g .= "<path d=\"M64 34 C93 34 109 51 109 71 C109 93 89 109 64 111 C39 109 19 93 19 71 C19 51 35 34 64 34 Z\" fill=\"{$f['mid']}\" stroke=\"$ink\" stroke-width=\"2.2\"/>";
    $g .= "<path d=\"M64 38 C70 46 72 54 64 62 C56 54 58 46 64 38 Z\" fill=\"{$f['light']}\" opacity=\"0.8\"/>";
    $white = $id === 'lud' ? '#ffffff' : '#fffaf4';
    $muzzleStroke = $id === 'lud' ? '#c9d0de' : 'none';
    $g .= "<path d=\"M64 66 C74 77 90 81 101 78 C97 97 81 109 64 109 C47 109 31 97 27 78 C38 81 54 77 64 66 Z\" fill=\"$white\" stroke=\"$muzzleStroke\" stroke-width=\"1.5\"/>";
    foreach ([48, 80] as $x) {
        $g .= "<ellipse cx=\"$x\" cy=\"67\" rx=\"6.2\" ry=\"7.2\" fill=\"$ink\"/><circle cx=\"".($x + 2).'" cy="64" r="2.2" fill="#fff"/>';
    }
    $g .= "<path d=\"M40 55 Q47 50 55 53\" fill=\"none\" stroke=\"{$f['deep']}\" stroke-width=\"2.6\" stroke-linecap=\"round\"/><path d=\"M88 55 Q81 50 73 53\" fill=\"none\" stroke=\"{$f['deep']}\" stroke-width=\"2.6\" stroke-linecap=\"round\"/>";
    $g .= "<path d=\"M57.5 85 Q64 80.5 70.5 85 Q67.5 91.5 64 92.5 Q60.5 91.5 57.5 85 Z\" fill=\"$ink\"/>";
    $g .= "<path d=\"M57 96 Q64 102 71 96\" fill=\"none\" stroke=\"$ink\" stroke-width=\"2.2\" stroke-linecap=\"round\"/>";
    $g .= match ($f['acc']) {
        'oculos' => "<g fill=\"rgba(255,255,255,0.2)\" stroke=\"$ink\" stroke-width=\"3\"><circle cx=\"48\" cy=\"67\" r=\"11\"/><circle cx=\"80\" cy=\"67\" r=\"11\"/></g><path d=\"M59 66 Q64 62 69 66\" fill=\"none\" stroke=\"$ink\" stroke-width=\"3\"/>",
        'cachecol' => "<path d=\"M26 106 Q64 124 102 106 L104 118 Q64 136 24 118 Z\" fill=\"$purple\" stroke=\"$ink\" stroke-width=\"2.2\"/><path d=\"M82 118 Q86 130 92 138 L102 134 Q96 126 94 114 Z\" fill=\"$purpleDeep\" stroke=\"$ink\" stroke-width=\"2.2\"/>",
        'fone' => "<path d=\"M17 72 Q15 14 64 12 Q113 14 111 72\" fill=\"none\" stroke=\"$ink\" stroke-width=\"6\" stroke-linecap=\"round\"/><rect x=\"8\" y=\"58\" width=\"16\" height=\"28\" rx=\"7\" fill=\"$ink\"/><rect x=\"104\" y=\"58\" width=\"16\" height=\"28\" rx=\"7\" fill=\"$ink\"/><rect x=\"11\" y=\"63\" width=\"6\" height=\"18\" rx=\"3\" fill=\"$purple\"/><rect x=\"111\" y=\"63\" width=\"6\" height=\"18\" rx=\"3\" fill=\"$purple\"/>",
        'lenco' => "<path d=\"M42 108 Q64 114 86 108 L64 132 Z\" fill=\"$purple\" stroke=\"$ink\" stroke-width=\"2.2\" stroke-linejoin=\"round\"/><circle cx=\"64\" cy=\"111\" r=\"5.5\" fill=\"$purpleDeep\" stroke=\"$ink\" stroke-width=\"2\"/>",
    };

    return "<circle cx=\"64\" cy=\"64\" r=\"62\" fill=\"#f5f5f8\"/>\n<g clip-path=\"url(#c-$id)\">$g</g>\n<circle cx=\"64\" cy=\"64\" r=\"62\" fill=\"none\" stroke=\"$purple\" stroke-width=\"3\"/>";
}

// ---------- C: emblema minimalista ----------
function styleC($id, $f)
{
    global $ink, $purple;
    $white = $id === 'lud' ? '#ffffff' : '#fff7ee';
    $furMain = $id === 'lud' ? '#e3e8f2' : $f['mid'];
    $g = poly([[27, 56], [35, 18], [57, 44]], $furMain).poly([[101, 56], [93, 18], [71, 44]], $furMain);
    $g .= poly([[33, 50], [37, 28], [50, 43]], $f['deep']).poly([[95, 50], [91, 28], [78, 43]], $f['deep']);
    $g .= poly([[24, 54], [64, 42], [104, 54], [96, 86], [64, 108], [32, 86]], $furMain);
    $g .= poly([[32, 86], [64, 72], [96, 86], [64, 108]], $white);
    $g .= "<circle cx=\"50\" cy=\"68\" r=\"3.8\" fill=\"$ink\"/><circle cx=\"78\" cy=\"68\" r=\"3.8\" fill=\"$ink\"/>";
    $g .= poly([[59, 92], [69, 92], [64, 98]], $ink);
    $g .= match ($f['acc']) {
        'oculos' => "<g fill=\"none\" stroke=\"$ink\" stroke-width=\"2.6\"><circle cx=\"50\" cy=\"68\" r=\"8.5\"/><circle cx=\"78\" cy=\"68\" r=\"8.5\"/><path d=\"M58.5 68 L69.5 68\"/></g>",
        'cachecol' => '<path d="M30 104 L64 116 L98 104 L98 112 L64 124 L30 112 Z" fill="#ffffff"/><path d="M80 116 L86 132 L94 129 L88 113 Z" fill="#e6e1fb"/>',
        'fone' => '<path d="M20 70 Q20 20 64 18 Q108 20 108 70" fill="none" stroke="#ffffff" stroke-width="4.5" stroke-linecap="round"/><rect x="13" y="60" width="13" height="22" rx="6" fill="#ffffff"/><rect x="102" y="60" width="13" height="22" rx="6" fill="#ffffff"/>',
        'lenco' => '<path d="M44 106 L84 106 L64 126 Z" fill="#ffffff"/><circle cx="64" cy="107" r="4.5" fill="#e6e1fb"/>',
    };

    return "<circle cx=\"64\" cy=\"64\" r=\"62\" fill=\"$purple\"/>\n<g clip-path=\"url(#c-$id)\">$g</g>";
}

$styles = [
    'a' => ['styleA', 'Opção A — Geométrico (família do logo)'],
    'b' => ['styleB', 'Opção B — Arredondado amigável'],
    'c' => ['styleC', 'Opção C — Emblema minimalista'],
];

$html = '<!doctype html><meta charset="utf-8"><body style="margin:0;font:15px Segoe UI,Arial,sans-serif;background:#f5f5f8;color:#1f1b2d;padding:24px">';
foreach ($styles as $key => [$fn, $label]) {
    @mkdir("$out/$key");
    $html .= "<h2 style=\"margin:8px 0 12px;color:#221a33\">$label</h2><div style=\"display:flex;gap:20px;margin-bottom:28px\">";
    foreach ($foxes as $id => $f) {
        file_put_contents("$out/$key/$id.svg", svg($id, $f['nome'], $fn($id, $f)));
        $html .= "<div style=\"background:#fff;border-radius:16px;padding:14px 18px;text-align:center;box-shadow:0 8px 24px rgba(31,27,45,.06)\"><img src=\"$key/$id.svg\" width=\"150\" height=\"150\"><div style=\"margin-top:6px;font-weight:600\">{$f['nome']}</div><div style=\"margin-top:10px;display:flex;gap:8px;justify-content:center;align-items:center\"><img src=\"$key/$id.svg\" width=\"40\" height=\"40\"><img src=\"$key/$id.svg\" width=\"28\" height=\"28\"></div></div>";
    }
    $html .= '</div>';
}
file_put_contents("$out/escolha.html", $html.'</body>');
echo "ok\n";
