<?php
/*
 * Extrai a geometria da logo (public/logonova.png, 500x500): facetas (cor + vértices) e contorno.
 * Saída: logo-geo.json
 */
ini_set('memory_limit', '1G');
$img = imagecreatefrompng(''.__DIR__.'/../../../public/core/brand/logo.png');
imagepalettetotruecolor($img);
$W = imagesx($img); $H = imagesy($img);

function rgba($img, $x, $y) {
    $c = imagecolorat($img, $x, $y);
    return [($c >> 16) & 255, ($c >> 8) & 255, $c & 255, ($c >> 24) & 127];
}
function dist($a, $b) { return sqrt(($a[0]-$b[0])**2 + ($a[1]-$b[1])**2 + ($a[2]-$b[2])**2); }
function lum($c) { return 0.299*$c[0] + 0.587*$c[1] + 0.114*$c[2]; }

// 1) Máscara da cabeça: pixel não transparente e não branco
$px = [];
for ($y = 0; $y < $H; $y++) for ($x = 0; $x < $W; $x++) $px[$y][$x] = rgba($img, $x, $y);

// 2) Regiões de cor uniforme (flood fill com tolerância pequena em relação à semente)
$label = array_fill(0, $H, array_fill(0, $W, 0));
$regions = [];
$id = 0;
for ($y = 0; $y < $H; $y++) {
    for ($x = 0; $x < $W; $x++) {
        if ($label[$y][$x] || $px[$y][$x][3] > 60) continue;
        $seed = $px[$y][$x];
        $id++;
        $stack = [[$x, $y]]; $label[$y][$x] = $id; $pts = []; $sum = [0,0,0];
        while ($stack) {
            [$cx, $cy] = array_pop($stack);
            $pts[] = [$cx, $cy]; $c = $px[$cy][$cx]; $sum[0]+=$c[0]; $sum[1]+=$c[1]; $sum[2]+=$c[2];
            foreach ([[1,0],[-1,0],[0,1],[0,-1]] as [$dx,$dy]) {
                $nx = $cx+$dx; $ny = $cy+$dy;
                if ($nx<0||$ny<0||$nx>=$W||$ny>=$H||$label[$ny][$nx]) continue;
                $n = $px[$ny][$nx];
                if ($n[3] > 60 || dist($n, $seed) > 7) continue;
                $label[$ny][$nx] = $id; $stack[] = [$nx, $ny];
            }
        }
        $n = count($pts);
        $regions[$id] = ['n' => $n, 'pts' => $pts, 'color' => [intdiv($sum[0],$n), intdiv($sum[1],$n), intdiv($sum[2],$n)]];
    }
}

// 3) Hull convexo de cada região grande
function hull($pts) {
    usort($pts, fn($a,$b) => $a[0]<=>$b[0] ?: $a[1]<=>$b[1]);
    $cross = fn($o,$a,$b) => ($a[0]-$o[0])*($b[1]-$o[1]) - ($a[1]-$o[1])*($b[0]-$o[0]);
    $lower = []; foreach ($pts as $p) { while (count($lower)>=2 && $cross($lower[count($lower)-2], $lower[count($lower)-1], $p) <= 0) array_pop($lower); $lower[] = $p; }
    $upper = []; foreach (array_reverse($pts) as $p) { while (count($upper)>=2 && $cross($upper[count($upper)-2], $upper[count($upper)-1], $p) <= 0) array_pop($upper); $upper[] = $p; }
    array_pop($lower); array_pop($upper);
    return array_merge($lower, $upper);
}
// simplifica polígono: remove vértices cuja remoção perde pouca área
function simplify($poly, $minArea = 6.0) {
    $area = fn($a,$b,$c) => abs(($b[0]-$a[0])*($c[1]-$a[1]) - ($c[0]-$a[0])*($b[1]-$a[1]))/2;
    while (count($poly) > 3) {
        $best = null; $bestA = INF; $n = count($poly);
        for ($i = 0; $i < $n; $i++) { $a = $area($poly[($i-1+$n)%$n], $poly[$i], $poly[($i+1)%$n]); if ($a < $bestA) { $bestA = $a; $best = $i; } }
        if ($bestA > $minArea) break;
        array_splice($poly, $best, 1);
    }
    return $poly;
}

$facets = [];
foreach ($regions as $rid => $r) {
    if ($r['n'] < 25) continue;
    $c = $r['color'];
    // ignora fundo branco
    if ($c[0] > 245 && $c[1] > 245 && $c[2] > 245) continue;
    // só borda de pixels (acelera o hull)
    $edge = [];
    foreach ($r['pts'] as [$x, $y]) {
        foreach ([[1,0],[-1,0],[0,1],[0,-1]] as [$dx,$dy]) { $nx=$x+$dx; $ny=$y+$dy; if ($nx<0||$ny<0||$nx>=$W||$ny>=$H||$label[$ny][$nx] !== $rid) { $edge[] = [$x,$y]; break; } }
    }
    $h = hull($edge);
    $hullArea = 0; $m = count($h); for ($i=0;$i<$m;$i++){ $a=$h[$i]; $b=$h[($i+1)%$m]; $hullArea += $a[0]*$b[1]-$b[0]*$a[1]; } $hullArea = abs($hullArea)/2;
    $solidity = $hullArea > 0 ? $r['n'] / $hullArea : 0;
    $facets[] = ['id' => $rid, 'n' => $r['n'], 'color' => sprintf('#%02x%02x%02x', ...$c), 'lum' => round(lum($c),1), 'solidity' => round($solidity, 2), 'poly' => simplify($h, 8.0)];
}
usort($facets, fn($a,$b) => $b['n'] <=> $a['n']);
echo count($facets)." regiões\n";
foreach ($facets as $f) echo $f['id']." n=".$f['n']." ".$f['color']." sol=".$f['solidity']." v=".count($f['poly'])."\n";
file_put_contents(__DIR__.'/logo-geo.json', json_encode($facets));
