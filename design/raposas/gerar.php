<?php
/*
 * Gera os desenhos das raposas da Foxy AI: 4 raposas × 3 estilos.
 *
 * Geometria IDÊNTICA à da logo (decisão de 2026-11-03): as 87 facetas, os 13 tons e o contorno vêm de
 * design/raposas/logo/geometria.json, extraídos do próprio PNG da logo (public/core/brand/logo.png) por
 * design/raposas/logo/{extrair,montar}.php. Cada raposa troca só as cores (tom a tom) e ganha o rosto e o
 * traço dela:
 *   Chico   — ruivo, com um dentinho para fora
 *   Lud     — raposinha clássica e delicada, sorriso sem dentes (com cílios)
 *   Lobinho — cinza e rajadinho (listras na testa e nas bochechas)
 *   Ayla    — caramelo, bebê brincalhona (olhos grandes, bochechas coradas e linguinha de fora)
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
 * ink = contorno e rosto; rim = filete claro do contorno (o lilás da logo); neon = cor das linhas no escuro.
 */
$foxes = [
    'chico' => ['nome' => 'Chico', 'dark' => '#4a1606', 'mid' => '#e0662a', 'light' => '#ffd9b8', 'ink' => '#3a1205', 'rim' => '#ffd2a8', 'neon' => '#ff9a4d'],
    'lud' => ['nome' => 'Lud', 'dark' => '#3b4a68', 'mid' => '#b8c4da', 'light' => '#ffffff', 'ink' => '#232c45', 'rim' => '#eef2fa', 'neon' => '#8fe0ff'],
    'lobinho' => ['nome' => 'Lobinho', 'dark' => '#23272f', 'mid' => '#8b93a1', 'light' => '#e6e9ee', 'ink' => '#1b1e24', 'rim' => '#e9ecf1', 'neon' => '#c9d6ea'],
    'ayla' => ['nome' => 'Ayla', 'dark' => '#5a300c', 'mid' => '#c4823a', 'light' => '#f8ddb0', 'ink' => '#3d2008', 'rim' => '#fbe3bd', 'neon' => '#ffc46b'],
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

// ---------- rosto e traço de cada raposa (mesmas posições em todos os estilos) ----------
function face(string $id, string $ink, string $mode): string
{
    $glow = $mode === 'neon' ? ' filter="url(#glow)"' : '';
    $eyeFill = $ink;
    $big = $id === 'ayla';
    // Rosto grande o bastante para ler em 40–48 px (tamanho do botão e do topo da janela).
    [$rx, $ry] = $big ? [28, 33] : [22, 27];
    $g = '';

    // Olhos
    foreach ([186, 313] as $x) {
        $g .= "<ellipse cx=\"$x\" cy=\"288\" rx=\"$rx\" ry=\"$ry\" fill=\"$eyeFill\"$glow/>";
        if ($mode !== 'neon') {
            $g .= '<circle cx="'.($x + 8).'" cy="'.(288 - ($big ? 12 : 9)).'" r="'.($big ? 10 : 8).'" fill="#fff"/>';
            $g .= '<circle cx="'.($x - 7).'" cy="'.(288 + ($big ? 12 : 9)).'" r="'.($big ? 4.5 : 3.4).'" fill="#fff" opacity="0.85"/>';
        }
    }
    if ($id === 'lud') { // cílios: raposinha delicada
        $g .= "<g stroke=\"$ink\" stroke-width=\"6\" stroke-linecap=\"round\" fill=\"none\"$glow><path d=\"M167 272 l-16 -10\"/><path d=\"M174 264 l-9 -15\"/><path d=\"M332 272 l16 -10\"/><path d=\"M325 264 l9 -15\"/></g>";
    }

    // Focinho e boca (e o dentinho/linguinha): 35% maiores, em volta do focinho
    $g .= '<g transform="translate(249.5 400) scale(1.35) translate(-249.5 -400)">';
    $g .= "<path d=\"M229 386 L270 386 Q272 388 270 392 L258 406 Q249.5 413 241 406 L229 392 Q227 388 229 386 Z\" fill=\"$ink\"$glow/>";
    if ($mode !== 'neon') {
        $g .= '<ellipse cx="241" cy="391" rx="6" ry="3" fill="#fff" opacity="0.45"/>';
    }
    $g .= "<path d=\"M249.5 409 L249.5 417 M226 414 Q237 428 249.5 417 Q262 428 273 414\" fill=\"none\" stroke=\"$ink\" stroke-width=\"5\" stroke-linecap=\"round\" stroke-linejoin=\"round\"$glow/>";

    if ($id === 'chico') { // um dentinho para fora
        $tooth = $mode === 'neon' ? 'none' : '#ffffff';
        $g .= "<path d=\"M243 419 L257 419 L256.5 433 Q250 437 243.5 433 Z\" fill=\"$tooth\" stroke=\"$ink\" stroke-width=\"3.2\" stroke-linejoin=\"round\"$glow/>";
    }
    if ($id === 'ayla') { // bebê brincalhona: linguinha de fora e bochechas coradas
        $g .= "<path d=\"M240 421 L259 421 L259 431 Q249.5 446 240 431 Z\" fill=\"#ff8fa3\" stroke=\"$ink\" stroke-width=\"3.2\" stroke-linejoin=\"round\"$glow/><path d=\"M249.5 424 L249.5 434\" stroke=\"#d9546f\" stroke-width=\"2.4\" stroke-linecap=\"round\"/>";
        if ($mode !== 'neon') {
            // bochechas fora do grupo ampliado do focinho
            $g .= '</g><g fill="#ff7f9b" opacity="0.5"><ellipse cx="146" cy="345" rx="28" ry="15"/><ellipse cx="353" cy="345" rx="28" ry="15"/></g><g>';
        }
    }

    return $g.'</g>';
}

/** Lobinho: listras (testa em "V" e três nas bochechas), recortadas pela cabeça. */
function stripes(string $color, string $mode): string
{
    $shapes = [
        [[214, 160], [249.5, 196], [285, 160], [285, 176], [249.5, 212], [214, 176]],
        [[226, 196], [249.5, 220], [273, 196], [273, 209], [249.5, 233], [226, 209]],
        [[50, 262], [118, 252], [120, 264], [52, 276]], [[64, 292], [124, 278], [127, 290], [68, 306]], [[88, 322], [132, 306], [136, 317], [93, 334]],
        [[449, 262], [381, 252], [379, 264], [447, 276]], [[435, 292], [375, 278], [372, 290], [431, 306]], [[411, 322], [367, 306], [363, 317], [406, 334]],
    ];
    $attr = match ($mode) {
        'facetas' => "fill=\"$color\" opacity=\"0.55\"",
        'linhas' => "fill=\"$color\" opacity=\"0.35\"",
        'neon' => "fill=\"none\" stroke=\"$color\" stroke-width=\"3\" stroke-linejoin=\"round\" filter=\"url(#glow)\"",
    };

    return '<g clip-path="url(#head)">'.implode('', array_map(fn ($s) => '<polygon points="'.pts($s)."\" $attr/>", $shapes)).'</g>';
}

function sparkle(string $fill): string
{
    return "<path d=\"M133 223 L136.5 239.5 L153 243 L136.5 246.5 L133 263 L129.5 246.5 L113 243 L129.5 239.5 Z\" fill=\"$fill\"/>";
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
        $g .= '<polygon points="'.pts($f['pts']).'" fill="none" stroke="#ffffff" stroke-opacity="0.22" stroke-width="1.2" stroke-linejoin="round"/>';
    }
    $body = "<circle cx=\"".CX.'" cy="'.CY.'" r="'.(CR - 2).'" fill="#f5f5f8" stroke="#e6e1f5" stroke-width="5"/>'
        ."<g clip-path=\"url(#head)\">$g</g>"
        .($id === 'lobinho' ? stripes($fox['ink'], 'facetas') : '')
        ."<polygon points=\"$head\" fill=\"none\" stroke=\"{$fox['ink']}\" stroke-width=\"15\" stroke-linejoin=\"round\"/>"
        ."<polygon points=\"$head\" fill=\"none\" stroke=\"{$fox['rim']}\" stroke-width=\"5\" stroke-linejoin=\"round\"/>"
        .sparkle('#ffffff').face($id, $fox['ink'], 'facetas');

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
    $tint = mix($fox['light'], '#ffffff', 0.55);
    $body = "<circle cx=\"".CX.'" cy="'.CY.'" r="'.(CR - 2).'" fill="#ffffff" stroke="#e6e1f5" stroke-width="5"/>'
        ."<polygon points=\"$head\" fill=\"$tint\"/>"
        ."<g clip-path=\"url(#head)\" fill=\"none\" stroke=\"".mix($fox['mid'], $fox['dark'], 0.35)."\" stroke-width=\"2.4\" stroke-linejoin=\"round\" opacity=\"0.8\">$edges</g>"
        .($id === 'lobinho' ? stripes($fox['ink'], 'linhas') : '')
        ."<polygon points=\"$head\" fill=\"none\" stroke=\"{$fox['ink']}\" stroke-width=\"11\" stroke-linejoin=\"round\"/>"
        .sparkle($fox['mid']).face($id, $fox['ink'], 'linhas');

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
        .'<filter id="glow" x="-30%" y="-30%" width="160%" height="160%"><feGaussianBlur stdDeviation="4" result="b"/><feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge></filter>';
    $body = '<circle cx="'.CX.'" cy="'.CY.'" r="'.CR.'" fill="url(#bg)"/>'
        ."<polygon points=\"$head\" fill=\"#15101f\"/>"
        ."<g clip-path=\"url(#head)\" fill=\"none\" stroke=\"$c\" stroke-width=\"2\" stroke-linejoin=\"round\" opacity=\"0.55\" filter=\"url(#glow)\">$edges</g>"
        .($id === 'lobinho' ? stripes($c, 'neon') : '')
        ."<polygon points=\"$head\" fill=\"none\" stroke=\"$c\" stroke-width=\"7\" stroke-linejoin=\"round\" filter=\"url(#glow)\"/>"
        .sparkle('#ffffff').face($id, $c, 'neon')
        .'<circle cx="'.CX.'" cy="'.CY.'" r="'.(CR - 3).'" fill="none" stroke="#8b6cf5" stroke-width="4" opacity="0.6"/>';

    return svg($uid, $fox['nome'], $defs, "<g clip-path=\"url(#c-$uid)\">$body</g>");
}

foreach (['facetas', 'linhas', 'neon'] as $style) {
    @mkdir("$out/$style", 0775, true);
    foreach ($foxes as $id => $fox) {
        $svg = $style($id, $fox, $geo);
        file_put_contents("$out/$style/$id.svg", $svg);

        if ($style === DEFAULT_STYLE) {
            file_put_contents("$out/$id.svg", $svg);
        }
    }
}

echo "Gerado em $out\n";
