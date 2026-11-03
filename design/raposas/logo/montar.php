<?php
/*
 * Monta a geometria limpa da logo a partir de logo-geo.json: vértices das facetas juntados (sem frestas),
 * contorno medido. Saída: geometria.json (outline + facets [pts, level]) e reproducao.svg (cores da logo).
 */
$facets = json_decode(file_get_contents(__DIR__.'/logo-geo.json'), true);

// Contorno (centro da faixa lilás), medido em contorno.php — simétrico em x = 249,5.
$half = [[94.2, 38.8], [191.6, 154.3], [249.5, 135.5]];
$mirror = fn ($p) => [round(499 - $p[0], 1), $p[1]];
$outline = [[94.2, 38.8], [191.6, 154.3], [249.5, 135.5], $mirror([191.6, 154.3]), $mirror([94.2, 38.8]),
    $mirror([89.4, 181.6]), $mirror([41.5, 282.6]), $mirror([163.6, 383]), [249.5, 464.5], [163.6, 383], [41.5, 282.6], [89.4, 181.6]];

// Facetas: tira contorno (as 3 maiores regiões não convexas), brilho branco e restos pequenos.
$keep = array_values(array_filter($facets, fn ($f) => $f['solidity'] > 0.7 && $f['solidity'] < 1.3 && $f['n'] >= 40 && count($f['poly']) >= 3
    && ! (hexdec(substr($f['color'], 1, 2)) > 235 && hexdec(substr($f['color'], 3, 2)) > 235)));

// Junta vértices próximos (o mesmo vértice da malha aparece um pouco diferente em cada faceta).
$all = [];
foreach ($keep as $fi => $f) foreach ($f['poly'] as $vi => $p) $all[] = ['f' => $fi, 'v' => $vi, 'x' => $p[0], 'y' => $p[1]];
$R = 11;
$clusters = [];
foreach ($all as $i => $p) {
    $found = null;
    foreach ($clusters as $ci => $c) { if (hypot($c['x'] - $p['x'], $c['y'] - $p['y']) <= $R) { $found = $ci; break; } }
    if ($found === null) { $clusters[] = ['x' => $p['x'], 'y' => $p['y'], 'sx' => $p['x'], 'sy' => $p['y'], 'n' => 1]; $found = count($clusters) - 1; }
    else { $c = &$clusters[$found]; $c['sx'] += $p['x']; $c['sy'] += $p['y']; $c['n']++; $c['x'] = $c['sx'] / $c['n']; $c['y'] = $c['sy'] / $c['n']; unset($c); }
    $all[$i]['c'] = $found;
}
// Vértices a até 14px do contorno vão para cima dele (e 3px para fora): a faceta encosta na borda sem fresta.
$segDist = function ($p, $a, $b) { $dx=$b[0]-$a[0]; $dy=$b[1]-$a[1]; $t=max(0,min(1,(($p[0]-$a[0])*$dx+($p[1]-$a[1])*$dy)/($dx*$dx+$dy*$dy))); $q=[$a[0]+$t*$dx,$a[1]+$t*$dy]; return [hypot($p[0]-$q[0],$p[1]-$q[1]), $q]; };
$cx = 249.5; $cy = 260;
foreach ($clusters as &$c) {
    $best = [INF, null];
    $n = count($outline);
    for ($i = 0; $i < $n; $i++) { $d = $segDist([$c["x"], $c["y"]], $outline[$i], $outline[($i+1)%$n]); if ($d[0] < $best[0]) $best = $d; }
    if ($best[0] <= 14) { $q = $best[1]; $vx=$q[0]-$cx; $vy=$q[1]-$cy; $len=hypot($vx,$vy) ?: 1; $c["x"]=$q[0]+3*$vx/$len; $c["y"]=$q[1]+3*$vy/$len; }
} unset($c);

// Vértice no centro (x≈249,5) fica exatamente no eixo; o resto, arredondado a 0,5.
foreach ($clusters as &$c) { $c['x'] = abs($c['x'] - 249.5) < 4 ? 249.5 : round($c['x'] * 2) / 2; $c['y'] = round($c['y'] * 2) / 2; } unset($c);

// Tons da logo em ordem de luminosidade → nível 0 (mais escuro) … N (mais claro)
$lums = array_unique(array_map(fn ($f) => (int) round($f['lum'] / 6), $keep)); sort($lums);
$levelOf = array_flip(array_values($lums));

$out = [];
foreach ($keep as $fi => $f) {
    $pts = [];
    foreach ($all as $p) if ($p['f'] === $fi) { $c = $clusters[$p['c']]; $pt = [$c['x'], $c['y']]; if (! $pts || end($pts) !== $pt) $pts[] = $pt; }
    if (count($pts) > 1 && $pts[0] === end($pts)) array_pop($pts);
    $pts = array_values(array_unique($pts, SORT_REGULAR));
    if (count($pts) < 3) continue;
    $out[] = ['pts' => $pts, 'color' => $f['color'], 'level' => $levelOf[(int) round($f['lum'] / 6)]];
}
require __DIR__."/under.php";
echo count($under)." triângulos de fundo, ".count($out)." facetas, ".count($clusters)." vértices, ".count($lums)." tons\n";
file_put_contents(__DIR__.'/geometria.json', json_encode(['outline' => $outline, 'facets' => $out, 'under' => $under, 'levels' => count($lums)]));

// Reprodução nas cores da logo, para comparar
$poly = fn ($pts) => implode(' ', array_map(fn ($p) => $p[0].','.$p[1], $pts));
$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 500 500" width="500" height="500"><defs><clipPath id="h"><polygon points="'.$poly($outline).'"/></clipPath></defs><rect width="500" height="500" fill="#fff"/><g clip-path="url(#h)">';
foreach ($under as $f) $svg .= '<polygon points="'.$poly($f['pts']).'" fill="'.$f['color'].'" stroke="'.$f['color'].'" stroke-width="1.5"/>';
foreach ($out as $f) $svg .= '<polygon points="'.$poly($f['pts']).'" fill="'.$f['color'].'" stroke="'.$f['color'].'" stroke-width="2" stroke-linejoin="round"/>';
foreach ($out as $f) $svg .= '<polygon points="'.$poly($f['pts']).'" fill="none" stroke="#ffffff" stroke-opacity="0.22" stroke-width="1.2" stroke-linejoin="round"/>';
$svg .= '</g><polygon points="'.$poly($outline).'" fill="none" stroke="#24156b" stroke-width="15" stroke-linejoin="round"/><polygon points="'.$poly($outline).'" fill="none" stroke="#b3a4ee" stroke-width="5" stroke-linejoin="round"/>';
$svg .= '<path d="M133 222 L137 239 L154 243 L137 247 L133 264 L129 247 L112 243 L129 239 Z" fill="#fff"/></svg>';
file_put_contents(__DIR__.'/reproducao.svg', $svg);
