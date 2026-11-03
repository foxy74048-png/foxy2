<?php
// Camada de fundo sem frestas: triangulação (Delaunay) de todos os vértices, cada triângulo com a cor da logo
// no centro dele. Fica por baixo das facetas; só aparece onde a faceta extraída não cobriu.
$img = imagecreatefrompng(''.__DIR__.'/../../../public/core/brand/logo.png');
imagepalettetotruecolor($img);
$points = array_map(fn ($c) => [$c['x'], $c['y']], $clusters);
foreach ($outline as $p) $points[] = $p;
$tris = delaunay($points);
$under = [];
foreach ($tris as [$a, $b, $c]) {
    $A = $points[$a]; $B = $points[$b]; $C = $points[$c];
    $gx = ($A[0] + $B[0] + $C[0]) / 3; $gy = ($A[1] + $B[1] + $C[1]) / 3;
    if (! pointInPoly([$gx, $gy], $outline)) continue;
    $votes = [];
    foreach ([[0, 0], [0.25, 0], [0, 0.25], [-0.25, 0.25]] as [$u, $v]) {
        $sx = (int) round($gx + $u * ($A[0] - $gx) + $v * ($B[0] - $gx)); $sy = (int) round($gy + $u * ($A[1] - $gy) + $v * ($B[1] - $gy));
        $sx = max(0, min(499, $sx)); $sy = max(0, min(499, $sy));
        $col = imagecolorat($img, $sx, $sy); $k = sprintf('#%02x%02x%02x', ($col >> 16) & 255, ($col >> 8) & 255, $col & 255);
        $votes[$k] = ($votes[$k] ?? 0) + 1;
    }
    arsort($votes); $color = array_key_first($votes);
    $lum = 0.299 * hexdec(substr($color, 1, 2)) + 0.587 * hexdec(substr($color, 3, 2)) + 0.114 * hexdec(substr($color, 5, 2));
    $bucket = (int) round($lum / 6); $nearest = null; $bd = INF;
    foreach ($lums as $l) { if (abs($l - $bucket) < $bd) { $bd = abs($l - $bucket); $nearest = $l; } }
    $under[] = ['pts' => [$A, $B, $C], 'color' => $color, 'level' => $levelOf[$nearest]];
}

function pointInPoly($p, $poly) { $in = false; $n = count($poly); for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) { [$xi, $yi] = $poly[$i]; [$xj, $yj] = $poly[$j]; if ((($yi > $p[1]) != ($yj > $p[1])) && ($p[0] < ($xj - $xi) * ($p[1] - $yi) / ($yj - $yi) + $xi)) $in = ! $in; } return $in; }
function delaunay(array $pts): array
{
    $n = count($pts);
    $pts[] = [-2000, -2000]; $pts[] = [3000, -2000]; $pts[] = [250, 4000];
    $tris = [[$n, $n + 1, $n + 2]];
    for ($i = 0; $i < $n; $i++) {
        $p = $pts[$i]; $bad = []; $keepT = [];
        foreach ($tris as $t) { if (inCircle($p, $pts[$t[0]], $pts[$t[1]], $pts[$t[2]])) $bad[] = $t; else $keepT[] = $t; }
        $edges = [];
        foreach ($bad as $t) foreach ([[$t[0], $t[1]], [$t[1], $t[2]], [$t[2], $t[0]]] as $e) { $k = min($e).'-'.max($e); $edges[$k] = array_key_exists($k, $edges) ? null : $e; }
        foreach ($edges as $e) if ($e) $keepT[] = [$e[0], $e[1], $i];
        $tris = $keepT;
    }
    return array_values(array_filter($tris, fn ($t) => max($t) < $n));
}
function inCircle($p, $a, $b, $c): bool
{
    $ax = $a[0] - $p[0]; $ay = $a[1] - $p[1]; $bx = $b[0] - $p[0]; $by = $b[1] - $p[1]; $cx = $c[0] - $p[0]; $cy = $c[1] - $p[1];
    $det = ($ax * $ax + $ay * $ay) * ($bx * $cy - $cx * $by) - ($bx * $bx + $by * $by) * ($ax * $cy - $cx * $ay) + ($cx * $cx + $cy * $cy) * ($ax * $by - $bx * $ay);
    $orient = ($b[0] - $a[0]) * ($c[1] - $a[1]) - ($b[1] - $a[1]) * ($c[0] - $a[0]);

    return $orient > 0 ? $det > 0 : $det < 0;
}
