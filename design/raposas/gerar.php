<?php
/*
 * Gera os desenhos das raposas da Foxy AI: 4 raposas × 3 estilos.
 *
 * Geometria IDÊNTICA à da logo (decisão de 2026-11-03): as 87 facetas, os 13 tons e o contorno vêm de
 * design/raposas/logo/geometria.json, extraídos do próprio PNG da logo (public/core/brand/logo.png) por
 * design/raposas/logo/{extrair,montar}.php. Cada raposa troca só as cores (tom a tom) e ganha rosto e marcas.
 *
 * Aparência a partir das fotos de referência do responsável (2026-11-03):
 *   Chico   — pretinho, olhos castanhos, com o dentinho de baixo para fora
 *   Lud     — escura (quase preta, rajadinha), focinho e queixo grisalhos, língua de fora, sem dentes à mostra
 *   Lobinho — rajado marrom e preto, olhos cor de mel, língua de fora
 *   Ayla    — caramelo, focinho e queixo mais claros, olhos grandes, lenço rosado no pescoço
 *
 * Estilos: facetas (padrão, igual à logo) · linhas (traços claros) · neon (linhas luminosas no escuro).
 *
 * Uso:  php design/raposas/gerar.php public/core/assistants
 * Saída: {estilo}/{raposa}.svg e {raposa}.svg (= estilo padrão, para endereços antigos).
 */
$out = rtrim($argv[1] ?? 'public/core/assistants', '/\\');
$geo = json_decode(file_get_contents(__DIR__.'/logo/geometria.json'), true);

const DEFAULT_STYLE = 'facetas';
const VIEW = '-38 -28 576 576';
const CX = 250;
const CY = 260;
const CR = 286;

/*
 * Cores: tons da logo (0 = mais escuro … 12 = mais claro) → escuro/médio/claro de cada raposa.
 * ink = contorno; rim = filete claro do contorno (o lilás da logo); neon = linhas no escuro;
 * iris = cor dos olhos; mouth = traço da boca; ring = contorno claro dos olhos (rosto escuro);
 * muzzle = mancha do focinho/queixo [cor, opacidade]; tongue/tooth/brindle/bandana/blush/big = traços.
 */
$foxes = [
    'chico' => ['nome' => 'Chico', 'dark' => '#0b0b0e', 'mid' => '#26262c', 'light' => '#5a5b63', 'ink' => '#050507', 'rim' => '#a7a6b2', 'neon' => '#d4d6e2',
        'iris' => '#6b4325', 'mouth' => '#d9d5e0', 'ring' => '#d9d5e0', 'muzzle' => null, 'tooth' => true],
    'lud' => ['nome' => 'Lud', 'dark' => '#120f0c', 'mid' => '#33291f', 'light' => '#6a5c4e', 'ink' => '#0a0806', 'rim' => '#c9c2b8', 'neon' => '#e6ded2',
        'iris' => '#5a3418', 'mouth' => '#1a130d', 'ring' => '#d8d0c4', 'muzzle' => ['#cfc8bf', 0.9], 'tongue' => true, 'brindle' => ['#5c4a36', 0.35]],
    'lobinho' => ['nome' => 'Lobinho', 'dark' => '#20130a', 'mid' => '#6b4627', 'light' => '#b78a58', 'ink' => '#160d06', 'rim' => '#e3c79f', 'neon' => '#f0b36a',
        'iris' => '#c08a2e', 'mouth' => '#140b05', 'ring' => null, 'muzzle' => ['#1a1009', 0.55], 'tongue' => true, 'brindle' => ['#140b05', 0.5]],
    'ayla' => ['nome' => 'Ayla', 'dark' => '#7a4719', 'mid' => '#d29a52', 'light' => '#f6dcae', 'ink' => '#3d2008', 'rim' => '#fbe6c4', 'neon' => '#ffc46b',
        'iris' => '#7a4416', 'mouth' => '#2b1606', 'ring' => null, 'muzzle' => ['#fbedd6', 0.75], 'bandana' => '#9e5c7c', 'blush' => true, 'big' => true],
];

function mix(string $a, string $b, float $t): string
{
    $ca = sscanf($a, '#%02x%02x%02x'); $cb = sscanf($b, '#%02x%02x%02x');

    return sprintf('#%02x%02x%02x', ...array_map(fn ($i) => (int) round($ca[$i] + ($cb[$i] - $ca[$i]) * $t), [0, 1, 2]));
}

function tone(array $fox, int $level, int $levels): string
{
    $t = $levels > 1 ? $level / ($levels - 1) : 0.5;

    return $t < 0.5 ? mix($fox['dark'], $fox['mid'], $t * 2) : mix($fox['mid'], $fox['light'], ($t - 0.5) * 2);
}

function pts(array $points): string
{
    return implode(' ', array_map(fn ($p) => $p[0].','.$p[1], $points));
}

function svg(string $uid, string $title, string $defs, string $body): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="'.VIEW."\" role=\"img\" aria-label=\"$title\">\n<title>$title</title>\n"
        ."<defs><clipPath id=\"c-$uid\"><circle cx=\"".CX.'" cy="'.CY.'" r="'.CR."\"/></clipPath>$defs</defs>\n$body\n</svg>\n";
}

// ---------- marcas no pelo (por baixo do rosto, recortadas pela cabeça) ----------
function markings(array $fox, string $mode): string
{
    $g = '';

    // Focinho e queixo (grisalho na Lud, claro na Ayla, escuro no Lobinho)
    if ($fox['muzzle'] && $mode !== 'neon') {
        [$color, $opacity] = $fox['muzzle'];
        $opacity = $mode === 'linhas' ? $opacity * 0.6 : $opacity;
        $g .= "<path d=\"M176 352 Q205 320 249.5 326 Q294 320 323 352 L336 392 Q318 440 249.5 470 Q181 440 163 392 Z\" fill=\"$color\" opacity=\"$opacity\"/>";
        if ($fox['nome'] === 'Lud') { // sobrancelhas grisalhas
            $g .= "<g fill=\"$color\" opacity=\"0.8\"><ellipse cx=\"190\" cy=\"246\" rx=\"20\" ry=\"8\" transform=\"rotate(-12 190 246)\"/><ellipse cx=\"309\" cy=\"246\" rx=\"20\" ry=\"8\" transform=\"rotate(12 309 246)\"/></g>";
        }
    }

    // Rajado: traços irregulares pelo rosto (sempre os mesmos — semente fixa)
    if (! empty($fox['brindle'])) {
        [$color, $opacity] = $fox['brindle'];
        mt_srand(7);
        $paths = '';
        for ($i = 0; $i < 46; $i++) {
            $x = mt_rand(40, 460); $y = mt_rand(110, 440);
            $len = mt_rand(30, 70); $bend = mt_rand(-14, 14); $tilt = mt_rand(-8, 8);
            $paths .= "<path d=\"M$x $y q$bend ".round($len / 2)." $tilt $len\"/>";
        }
        $attr = $mode === 'neon'
            ? "stroke=\"{$fox['neon']}\" stroke-width=\"2.2\" opacity=\"0.45\" filter=\"url(#glow)\""
            : "stroke=\"$color\" stroke-width=\"".($mode === 'linhas' ? 3 : 6)."\" opacity=\"".($mode === 'linhas' ? $opacity * 0.8 : $opacity).'"';
        $g .= "<g fill=\"none\" stroke-linecap=\"round\" $attr>$paths</g>";
    }

    return $g === '' ? '' : "<g clip-path=\"url(#head)\">$g</g>";
}

// ---------- rosto (mesmas posições em todos os estilos) ----------
function face(array $fox, string $mode): string
{
    $neon = $mode === 'neon';
    $glow = $neon ? ' filter="url(#glow)"' : '';
    $line = $neon ? $fox['neon'] : $fox['ink'];
    $mouth = $neon ? $fox['neon'] : $fox['mouth'];
    $big = ! empty($fox['big']);
    // Rosto grande o bastante para ler em 40–48 px (tamanho do botão e do topo da janela).
    [$rx, $ry] = $big ? [28, 32] : [23, 26];
    $g = '';

    // Olhos: íris na cor do cachorro, pupila, brilhos; rosto escuro ganha um contorno claro
    foreach ([186, 313] as $x) {
        if ($neon) {
            $g .= "<ellipse cx=\"$x\" cy=\"288\" rx=\"$rx\" ry=\"$ry\" fill=\"none\" stroke=\"$line\" stroke-width=\"5\"$glow/><circle cx=\"$x\" cy=\"290\" r=\"".($rx * 0.45)."\" fill=\"$line\"$glow/>";
            continue;
        }
        if ($fox['ring']) {
            $g .= "<ellipse cx=\"$x\" cy=\"288\" rx=\"".($rx + 6).'" ry="'.($ry + 6)."\" fill=\"none\" stroke=\"{$fox['ring']}\" stroke-width=\"4\" opacity=\"0.7\"/>";
        }
        $g .= "<ellipse cx=\"$x\" cy=\"288\" rx=\"$rx\" ry=\"$ry\" fill=\"{$fox['iris']}\" stroke=\"{$fox['ink']}\" stroke-width=\"4.5\"/>";
        $g .= '<ellipse cx="'.$x.'" cy="291" rx="'.($rx * 0.55).'" ry="'.($ry * 0.6)."\" fill=\"#0a0705\"/>";
        $g .= '<circle cx="'.($x + 8).'" cy="'.(288 - ($big ? 11 : 9)).'" r="'.($big ? 9.5 : 7.5).'" fill="#fff"/>';
        $g .= '<circle cx="'.($x - 8).'" cy="'.(288 + ($big ? 11 : 9)).'" r="'.($big ? 4.5 : 3.4).'" fill="#fff" opacity="0.85"/>';
    }

    // Focinho, boca, língua e dente: 35% maiores, em volta do focinho
    $g .= '<g transform="translate(249.5 400) scale(1.35) translate(-249.5 -400)">';
    $noseStroke = $fox['ring'] && ! $neon ? " stroke=\"{$fox['ring']}\" stroke-width=\"2.4\" stroke-opacity=\"0.7\"" : '';
    $g .= '<path d="M229 386 L270 386 Q272 388 270 392 L258 406 Q249.5 413 241 406 L229 392 Q227 388 229 386 Z" fill="'.($neon ? $line : '#0a0806')."\"$noseStroke$glow/>";
    if (! $neon) {
        $g .= '<ellipse cx="241" cy="391" rx="6" ry="3" fill="#fff" opacity="0.5"/>';
    }

    if (! empty($fox['tongue'])) { // boca aberta, língua de fora (sem dentes à mostra)
        $g .= "<path d=\"M226 413 Q249.5 432 273 413 Q262 422 249.5 422 Q237 422 226 413 Z\" fill=\"".($neon ? 'none' : '#2a0f12')."\" stroke=\"$mouth\" stroke-width=\"4\" stroke-linejoin=\"round\"$glow/>";
        $g .= '<path d="M238 420 L261 420 L261 433 Q249.5 450 238 433 Z" fill="'.($neon ? 'none' : '#ff8fa3')."\" stroke=\"".($neon ? '#ff8fa3' : $fox['ink'])."\" stroke-width=\"3\" stroke-linejoin=\"round\"$glow/>";
        if (! $neon) {
            $g .= '<path d="M249.5 423 L249.5 436" stroke="#d9546f" stroke-width="2.4" stroke-linecap="round"/>';
        }
    } else {
        $g .= "<path d=\"M249.5 409 L249.5 417 M226 414 Q237 428 249.5 417 Q262 428 273 414\" fill=\"none\" stroke=\"$mouth\" stroke-width=\"5\" stroke-linecap=\"round\" stroke-linejoin=\"round\"$glow/>";
    }

    if (! empty($fox['tooth'])) { // dentinho de baixo para fora, subindo por cima da boca
        $g .= '<path d="M253 434 L278 434 L275 405 Q265.5 393 256 405 Z" fill="'.($neon ? 'none' : '#ffffff')."\" stroke=\"".($neon ? $line : $fox['ink'])."\" stroke-width=\"3.4\" stroke-linejoin=\"round\"$glow/>";
    }
    $g .= '</g>';

    if (! empty($fox['blush']) && ! $neon) {
        $g .= '<g fill="#ff7f9b" opacity="0.45"><ellipse cx="146" cy="345" rx="28" ry="15"/><ellipse cx="353" cy="345" rx="28" ry="15"/></g>';
    }

    return $g;
}

/** Lenço amarrado no pescoço (Ayla): fica atrás da cabeça e aparece dos lados do queixo e embaixo dele. */
function bandana(array $fox, string $mode): string
{
    if (empty($fox['bandana'])) {
        return '';
    }
    $b = $fox['bandana'];
    $neon = $mode === 'neon';
    $g = '<path d="M108 392 Q249.5 470 391 392 L362 448 L249.5 540 L137 448 Z" fill="'.($neon ? 'none' : $b).'" stroke="'.($neon ? $b : mix($b, '#000000', 0.35)).'" stroke-width="5" stroke-linejoin="round"'.($neon ? ' filter="url(#glow)"' : '').'/>';
    if (! $neon) {
        $dots = '';
        foreach ([[150, 430], [178, 455], [208, 480], [291, 480], [321, 455], [349, 430], [230, 505], [269, 505], [249.5, 525]] as [$x, $y]) {
            $dots .= "<circle cx=\"$x\" cy=\"$y\" r=\"4\"/>";
        }
        $g .= '<g fill="'.mix($b, '#ffffff', 0.55)."\" opacity=\"0.85\">$dots</g>";
    }

    return $g;
}

function sparkle(string $fill): string
{
    return "<path d=\"M133 223 L136.5 239.5 L153 243 L136.5 246.5 L133 263 L129.5 246.5 L113 243 L129.5 239.5 Z\" fill=\"$fill\"/>";
}

function glowFilter(): string
{
    return '<filter id="glow" x="-30%" y="-30%" width="160%" height="160%"><feGaussianBlur stdDeviation="4" result="b"/><feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge></filter>';
}

// ---------- facetas: igual à logo, nas cores da raposa ----------
function facetas(string $id, array $fox, array $geo): string
{
    $uid = "facetas-$id";
    $head = pts($geo['outline']);
    $g = '';
    foreach ([...$geo['under'], ...$geo['facets']] as $f) {
        $c = tone($fox, $f['level'], $geo['levels']);
        $g .= '<polygon points="'.pts($f['pts'])."\" fill=\"$c\" stroke=\"$c\" stroke-width=\"1.6\" stroke-linejoin=\"round\"/>";
    }
    foreach ($geo['facets'] as $f) {
        $g .= '<polygon points="'.pts($f['pts']).'" fill="none" stroke="#ffffff" stroke-opacity="0.18" stroke-width="1.2" stroke-linejoin="round"/>';
    }
    $body = '<circle cx="'.CX.'" cy="'.CY.'" r="'.(CR - 2).'" fill="#f5f5f8" stroke="#e6e1f5" stroke-width="5"/>'
        .bandana($fox, 'facetas')
        ."<g clip-path=\"url(#head)\">$g</g>"
        .markings($fox, 'facetas')
        ."<polygon points=\"$head\" fill=\"none\" stroke=\"{$fox['ink']}\" stroke-width=\"15\" stroke-linejoin=\"round\"/>"
        ."<polygon points=\"$head\" fill=\"none\" stroke=\"{$fox['rim']}\" stroke-width=\"5\" stroke-linejoin=\"round\"/>"
        .sparkle('#ffffff').face($fox, 'facetas');

    return svg($uid, $fox['nome'], "<clipPath id=\"head\"><polygon points=\"$head\"/></clipPath>", "<g clip-path=\"url(#c-$uid)\">$body</g>");
}

// ---------- linhas: a mesma malha só em traços, sobre fundo claro ----------
function linhas(string $id, array $fox, array $geo): string
{
    $uid = "linhas-$id";
    $head = pts($geo['outline']);
    $edges = '';
    foreach ($geo['facets'] as $f) {
        $edges .= '<polygon points="'.pts($f['pts']).'"/>';
    }
    $tint = mix($fox['light'], '#ffffff', 0.6);
    $body = '<circle cx="'.CX.'" cy="'.CY.'" r="'.(CR - 2).'" fill="#ffffff" stroke="#e6e1f5" stroke-width="5"/>'
        .bandana($fox, 'linhas')
        ."<polygon points=\"$head\" fill=\"$tint\"/>"
        .markings($fox, 'linhas')
        ."<g clip-path=\"url(#head)\" fill=\"none\" stroke=\"".mix($fox['mid'], $fox['dark'], 0.3)."\" stroke-width=\"2.4\" stroke-linejoin=\"round\" opacity=\"0.75\">$edges</g>"
        ."<polygon points=\"$head\" fill=\"none\" stroke=\"{$fox['ink']}\" stroke-width=\"11\" stroke-linejoin=\"round\"/>"
        .sparkle($fox['mid']).face(['ring' => null, 'mouth' => $fox['ink']] + $fox, 'linhas');

    return svg($uid, $fox['nome'], "<clipPath id=\"head\"><polygon points=\"$head\"/></clipPath>", "<g clip-path=\"url(#c-$uid)\">$body</g>");
}

// ---------- neon: a mesma malha em linhas luminosas no escuro ----------
function neon(string $id, array $fox, array $geo): string
{
    $uid = "neon-$id";
    $head = pts($geo['outline']);
    $c = $fox['neon'];
    $edges = '';
    foreach ($geo['facets'] as $f) {
        $edges .= '<polygon points="'.pts($f['pts']).'"/>';
    }
    $defs = "<clipPath id=\"head\"><polygon points=\"$head\"/></clipPath>"
        .'<radialGradient id="bg" cx="50%" cy="42%" r="65%"><stop offset="0" stop-color="#2c2147"/><stop offset="1" stop-color="#120d1c"/></radialGradient>'
        .glowFilter();
    $body = '<circle cx="'.CX.'" cy="'.CY.'" r="'.CR.'" fill="url(#bg)"/>'
        .bandana($fox, 'neon')
        ."<polygon points=\"$head\" fill=\"#15101f\"/>"
        ."<g clip-path=\"url(#head)\" fill=\"none\" stroke=\"$c\" stroke-width=\"2\" stroke-linejoin=\"round\" opacity=\"0.5\" filter=\"url(#glow)\">$edges</g>"
        .markings($fox, 'neon')
        ."<polygon points=\"$head\" fill=\"none\" stroke=\"$c\" stroke-width=\"7\" stroke-linejoin=\"round\" filter=\"url(#glow)\"/>"
        .sparkle('#ffffff').face($fox, 'neon')
        .'<circle cx="'.CX.'" cy="'.CY.'" r="'.(CR - 3).'" fill="none" stroke="#8b6cf5" stroke-width="4" opacity="0.6"/>';

    return svg($uid, $fox['nome'], $defs, "<g clip-path=\"url(#c-$uid)\">$body</g>");
}

foreach (['facetas', 'linhas', 'neon'] as $style) {
    @mkdir("$out/$style", 0775, true);
    foreach ($foxes as $id => $fox) {
        $fox += ['tongue' => false, 'tooth' => false, 'brindle' => null, 'bandana' => null, 'blush' => false, 'big' => false];
        $svg = $style($id, $fox, $geo);
        file_put_contents("$out/$style/$id.svg", $svg);

        if ($style === DEFAULT_STYLE) {
            file_put_contents("$out/$id.svg", $svg);
        }
    }
}

echo "Gerado em $out\n";
