<?php
/*
 * Gera os desenhos das raposas da Foxy AI: 4 raposas × 3 estilos (escolhidos em 2026-11-02).
 *   facetas — "de cristal", irmã do logo (padrão)
 *   linhas  — traços geométricos claros (tecnologia premium)
 *   neon    — linhas luminosas sobre fundo escuro (mais tecnológico)
 *
 * Uso:  php design/raposas/gerar.php public/core/assistants
 * Saída: {estilo}/{raposa}.svg e {raposa}.svg (= estilo padrão, para endereços antigos).
 */
$out = rtrim($argv[1] ?? 'public/core/assistants', '/\\');

const INK = '#221a33';
const PURPLE = '#5b3fd1';
const VIOLET = '#8b6cf5';
const DEFAULT_STYLE = 'facetas';

$foxes = [
    'chico' => ['nome' => 'Chico', 'acc' => 'oculos',
        'facetas' => ['#f39a5e', '#e0662a', '#b8481c', '#7e2f10'], 'linhas' => ['#f08a3e', '#b8481c'], 'neon' => ['#ffc061', '#f06a1d', '#ffe0a3']],
    'lud' => ['nome' => 'Lud', 'acc' => 'cachecol',
        'facetas' => ['#ffffff', '#eef1f7', '#d3d9e6', '#9aa4ba'], 'linhas' => ['#7cc4ec', '#3a7db0'], 'neon' => ['#f2fbff', '#6fd3ff', '#c8f3ff']],
    'lobinho' => ['nome' => 'Lobinho', 'acc' => 'fone',
        'facetas' => ['#c3c8d1', '#9aa1ad', '#737a86', '#4a505b'], 'linhas' => ['#9aa6b8', '#4f5a6b'], 'neon' => ['#e3e8f0', '#8aa0c4', '#a8ecff']],
    'ayla' => ['nome' => 'Ayla', 'acc' => 'lenco',
        'facetas' => ['#f5d58c', '#dfb058', '#bb8b33', '#86621f'], 'linhas' => ['#e9b64e', '#a8761f'], 'neon' => ['#fff0a8', '#e3a634', '#fff3c4']],
];

const OUTLINE = 'M34 16 L50 40 L64 32 L78 40 L94 16 L102 52 L110 72 L88 96 L64 110 L40 96 L18 72 L26 52 Z';
const FACET_LINES = [[26, 52, 46, 62], [46, 62, 64, 46], [64, 46, 82, 62], [82, 62, 102, 52], [18, 72, 46, 62], [82, 62, 110, 72], [46, 62, 52, 80], [82, 62, 76, 80], [52, 80, 64, 92], [76, 80, 64, 92], [40, 96, 52, 80], [88, 96, 76, 80], [64, 32, 64, 46]];

function svg(string $uid, string $title, string $defs, string $body): string
{
    return "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 128 128\" role=\"img\" aria-label=\"$title\">\n<title>$title</title>\n"
        ."<defs><clipPath id=\"c-$uid\"><circle cx=\"64\" cy=\"64\" r=\"62\"/></clipPath>$defs</defs>\n$body\n</svg>\n";
}

function lines(): string
{
    return implode('', array_map(fn ($l) => "<line x1=\"{$l[0]}\" y1=\"{$l[1]}\" x2=\"{$l[2]}\" y2=\"{$l[3]}\"/>", FACET_LINES));
}

function accessory(string $acc, string $stroke, string $fill, float $width, string $extra = ''): string
{
    $s = "stroke=\"$stroke\" stroke-width=\"$width\" stroke-linejoin=\"round\" fill=\"$fill\" $extra";

    return match ($acc) {
        'oculos' => "<g $s><polygon points=\"37,57 43,52.5 56,52.5 59,59 55,68 41,68\"/><polygon points=\"91,57 85,52.5 72,52.5 69,59 73,68 87,68\"/></g><path d=\"M59 58 L69 58\" stroke=\"$stroke\" stroke-width=\"$width\" $extra/>",
        'cachecol' => "<path d=\"M30 100 L64 116 L98 100 L101 109 L64 125 L27 109 Z\" $s/><path d=\"M80 117 L87 130 L95 126 L89 113 Z\" $s/>",
        'fone' => "<path d=\"M14 66 L16 30 L36 10 L92 10 L112 30 L114 66\" fill=\"none\" stroke=\"$stroke\" stroke-width=\"".($width + 1)."\" stroke-linejoin=\"round\" $extra/><rect x=\"8\" y=\"56\" width=\"11\" height=\"22\" rx=\"3\" $s/><rect x=\"109\" y=\"56\" width=\"11\" height=\"22\" rx=\"3\" $s/>",
        'lenco' => "<path d=\"M42 104 L86 104 L64 126 Z\" $s/><path d=\"M58 103 L70 103 L67 112 L61 112 Z\" $s/>",
    };
}

function friendlyFace(string $eye): string
{
    return "<g fill=\"$eye\"><ellipse cx=\"48\" cy=\"63\" rx=\"5.6\" ry=\"6.4\"/><ellipse cx=\"80\" cy=\"63\" rx=\"5.6\" ry=\"6.4\"/></g>"
        .'<g fill="#fff"><circle cx="50" cy="60.5" r="2"/><circle cx="82" cy="60.5" r="2"/></g>';
}

// ---------- facetas: "de cristal", irmã do logo ----------
function facetas(string $id, array $f): string
{
    [$light, $mid, $dark, $deep] = $f['facetas'];
    $P = fn ($pts, $c) => '<polygon points="'.implode(' ', array_map(fn ($p) => $p[0].','.$p[1], $pts))."\" fill=\"$c\" stroke=\"$c\" stroke-width=\"0.5\" stroke-linejoin=\"round\"/>";
    $T1 = [34, 16]; $E1 = [50, 40]; $H = [64, 32]; $E2 = [78, 40]; $T2 = [94, 16]; $B2 = [102, 52]; $C2 = [110, 72]; $J2 = [88, 96]; $X = [64, 110]; $J1 = [40, 96]; $C1 = [18, 72]; $B1 = [26, 52];
    $F = [64, 46]; $Le = [46, 62]; $Re = [82, 62]; $M1 = [52, 80]; $M2 = [76, 80]; $N = [64, 92];
    $w = $id === 'lud' ? '#ffffff' : '#fffaf4';
    $w2 = $id === 'lud' ? '#e4e9f2' : '#f3ebe2';
    $g = $P([$T1, $E1, $B1], $mid).$P([$T1, [45, 35], [33, 40]], $deep).$P([$T2, $E2, $B2], $dark).$P([$T2, [83, 35], [95, 40]], $deep)
        .$P([$E1, $H, $F], $light).$P([$H, $E2, $F], $mid).$P([$B1, $E1, $Le], $dark).$P([$E1, $F, $Le], $mid)
        .$P([$B2, $E2, $Re], $mid).$P([$E2, $F, $Re], $dark).$P([$F, $Le, $M1], $mid).$P([$F, $Re, $M2], $dark)
        .$P([$F, $M1, $N], $light).$P([$F, $M2, $N], $mid).$P([$B1, $Le, $C1], $mid).$P([$B2, $Re, $C2], $dark)
        .$P([$C1, $Le, $M1], $w).$P([$C1, $M1, $J1], $w2).$P([$C2, $Re, $M2], $w2).$P([$C2, $M2, $J2], $w)
        .$P([$J1, $M1, $N], $w).$P([$J2, $M2, $N], $w2).$P([$J1, $N, $X], $w2).$P([$J2, $N, $X], $w);
    $g .= '<path d="'.OUTLINE.'" fill="none" stroke="'.INK.'" stroke-width="3" stroke-linejoin="round"/>';
    $g .= friendlyFace(INK).'<polygon points="59.5,89 68.5,89 66,95 62,95" fill="'.INK.'"/><path d="M58 99 Q64 103 70 99" fill="none" stroke="'.INK.'" stroke-width="1.8" stroke-linecap="round"/>';
    $g .= '<path d="M30 66 l1.4 3.8 3.8 1.4 -3.8 1.4 -1.4 3.8 -1.4 -3.8 -3.8 -1.4 3.8 -1.4z" fill="#fff"/>';
    $g .= accessory($f['acc'], INK, $f['acc'] === 'oculos' ? 'rgba(255,255,255,0.25)' : PURPLE, 2.6);
    $uid = "facetas-$id";

    return svg($uid, $f['nome'], '', "<circle cx=\"64\" cy=\"64\" r=\"62\" fill=\"#f5f5f8\" stroke=\"#e6e1f5\" stroke-width=\"2\"/>\n<g clip-path=\"url(#c-$uid)\">$g</g>");
}

// ---------- linhas: traços geométricos claros ----------
function linhas(string $id, array $f): string
{
    [$c1, $c2] = $f['linhas'];
    $uid = "linhas-$id";
    $line = "url(#l-$uid)";
    $defs = "<linearGradient id=\"l-$uid\" x1=\"0\" y1=\"0\" x2=\"1\" y2=\"1\"><stop offset=\"0\" stop-color=\"$c1\"/><stop offset=\"1\" stop-color=\"$c2\"/></linearGradient>";
    $g = '<g stroke="'.VIOLET.'" stroke-width="1.6" opacity="0.6">'.lines().'</g>'
        ."<g fill=\"$line\" opacity=\"0.9\"><polygon points=\"61,35 64,33.5 67,35 65.5,50 62.5,50\"/><polygon points=\"36,23 45,35 39,36\"/><polygon points=\"92,23 83,35 89,36\"/></g>"
        .'<polygon points="32,86 64,74 96,86 64,108" fill="'.VIOLET.'" opacity="0.08"/>'
        .'<path d="'.OUTLINE."\" fill=\"none\" stroke=\"$line\" stroke-width=\"3.6\" stroke-linejoin=\"round\"/>"
        .'<g fill="'.PURPLE.'"><circle cx="64" cy="46" r="2.4"/><circle cx="26" cy="52" r="2"/><circle cx="102" cy="52" r="2"/><circle cx="64" cy="110" r="2"/></g>'
        .friendlyFace($c2)
        ."<polygon points=\"59.5,89 68.5,89 66,95 62,95\" fill=\"$line\"/><path d=\"M58 99 Q64 103 70 99\" fill=\"none\" stroke=\"$c2\" stroke-width=\"2\" stroke-linecap=\"round\"/>"
        .accessory($f['acc'], $line, $f['acc'] === 'oculos' ? 'none' : 'rgba(91,63,209,0.18)', 2.8);

    return svg($uid, $f['nome'], $defs, "<circle cx=\"64\" cy=\"64\" r=\"62\" fill=\"#ffffff\" stroke=\"#e6e1f5\" stroke-width=\"2\"/>\n<g clip-path=\"url(#c-$uid)\">$g</g>");
}

// ---------- neon: linhas luminosas sobre fundo escuro ----------
function neon(string $id, array $f): string
{
    [$c1, $c2, $eye] = $f['neon'];
    $uid = "neon-$id";
    $line = "url(#l-$uid)";
    $glow = "filter=\"url(#g-$uid)\"";
    $defs = "<radialGradient id=\"bg-$uid\" cx=\"50%\" cy=\"42%\" r=\"65%\"><stop offset=\"0\" stop-color=\"#2c2147\"/><stop offset=\"1\" stop-color=\"#120d1c\"/></radialGradient>"
        ."<linearGradient id=\"l-$uid\" x1=\"0\" y1=\"0\" x2=\"1\" y2=\"1\"><stop offset=\"0\" stop-color=\"$c1\"/><stop offset=\"1\" stop-color=\"$c2\"/></linearGradient>"
        ."<filter id=\"g-$uid\" x=\"-40%\" y=\"-40%\" width=\"180%\" height=\"180%\"><feGaussianBlur stdDeviation=\"1.5\" result=\"b\"/><feMerge><feMergeNode in=\"b\"/><feMergeNode in=\"SourceGraphic\"/></feMerge></filter>";
    $nodes = implode('', array_map(fn ($p) => "<circle cx=\"{$p[0]}\" cy=\"{$p[1]}\" r=\"2\"/>", [[64, 46], [46, 62], [82, 62], [52, 80], [76, 80], [26, 52], [102, 52], [18, 72], [110, 72], [64, 110]]));
    $g = '<g stroke="'.VIOLET.'" stroke-width="1.2" fill="none" opacity="0.85"><path d="M110 72 L118 72 L122 66"/><path d="M18 72 L10 72 L7 78"/><path d="M64 110 L64 118"/><path d="M94 16 L102 10 L110 12"/><path d="M34 16 L26 10 L18 12"/><polygon points="64,4 68,6.3 68,10.7 64,13 60,10.7 60,6.3"/></g>'
        .'<g fill="'.VIOLET.'" opacity="0.9"><circle cx="122" cy="66" r="1.8"/><circle cx="7" cy="78" r="1.8"/><circle cx="64" cy="118" r="1.8"/><rect x="112" y="80" width="3" height="3"/><rect x="13" y="84" width="3" height="3"/></g>'
        .'<g stroke="'.VIOLET."\" stroke-width=\"1.3\" opacity=\"0.9\" $glow>".lines().'<line x1="50" y1="40" x2="46" y2="62"/><line x1="78" y1="40" x2="82" y2="62"/></g>'
        ."<g fill=\"$line\" opacity=\"0.9\"><polygon points=\"61,35 64,33.5 67,35 65.5,50 62.5,50\"/><polygon points=\"53,43 57,40 59,52 56,55\"/><polygon points=\"75,43 71,40 69,52 72,55\"/><polygon points=\"36,23 45,35 39,36\"/><polygon points=\"34,32 43,41 36,43\"/><polygon points=\"92,23 83,35 89,36\"/><polygon points=\"94,32 85,41 92,43\"/><polygon points=\"27,67 38,69 30,74\"/><polygon points=\"101,67 90,69 98,74\"/></g>"
        .'<polygon points="58,66 70,66 67.5,88 60.5,88" fill="'.VIOLET.'" opacity="0.35"/>'
        .'<path d="'.OUTLINE."\" fill=\"none\" stroke=\"$line\" stroke-width=\"2.8\" stroke-linejoin=\"round\" $glow/>"
        ."<g fill=\"$line\" $glow>$nodes</g>"
        ."<g fill=\"$eye\" $glow><polygon points=\"40,62 49,57 56,63 47,66.5\"/><polygon points=\"88,62 79,57 72,63 81,66.5\"/></g>"
        ."<polygon points=\"59.5,90 68.5,90 66.5,96 61.5,96\" fill=\"$line\" $glow/>"
        .accessory($f['acc'], $line, 'none', 2.4, $glow);

    return svg($uid, $f['nome'], $defs, "<circle cx=\"64\" cy=\"64\" r=\"63\" fill=\"url(#bg-$uid)\"/>\n<g clip-path=\"url(#c-$uid)\">$g</g>\n<circle cx=\"64\" cy=\"64\" r=\"62.5\" fill=\"none\" stroke=\"".VIOLET.'" stroke-width="1.5" opacity="0.6"/>');
}

foreach (['facetas', 'linhas', 'neon'] as $style) {
    @mkdir("$out/$style", 0775, true);
    foreach ($foxes as $id => $f) {
        $svg = $style($id, $f);
        file_put_contents("$out/$style/$id.svg", $svg);

        if ($style === DEFAULT_STYLE) {
            file_put_contents("$out/$id.svg", $svg);
        }
    }
}

echo "Gerado em $out\n";
