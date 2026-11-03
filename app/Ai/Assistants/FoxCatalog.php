<?php

namespace App\Ai\Assistants;

/**
 * As raposas da Foxy (docs/07, seção 4; nomes definidos em 2026-11-01). Mudam o nome, o visual e o
 * jeito de falar — as regras de segurança e a precisão são as mesmas para todas.
 */
class FoxCatalog
{
    public const DEFAULT = 'lud';

    public const DEFAULT_VISUAL = 'facetas';

    /** Estilos do desenho, à escolha de cada pessoa (definidos em 2026-11-02; gerador em design/raposas). */
    private const VISUALS = [
        'facetas' => 'Facetas — "de cristal", da família do logo',
        'linhas' => 'Linhas — traços geométricos claros',
        'neon' => 'Neon — linhas luminosas, mais tecnológico',
    ];

    private const FOXES = [
        'chico' => [
            'name' => 'Chico',
            'article' => 'o',
            'look' => 'Pretinho, de olhos castanhos, com o dentinho de baixo para fora.',
            'style' => 'Objetivo e direto: vai ao ponto, com respostas curtas e sem rodeios.',
            'sample' => 'Agenda › clique no atendimento › Remarcar › escolha o horário › Confirmar. Pronto.',
        ],
        'lud' => [
            'name' => 'Lud',
            'article' => 'a',
            'look' => 'Escurinha, de focinho e queixo grisalhos, com a língua de fora.',
            'style' => 'Acolhedora e paciente: explica passo a passo, com calma, e confere se ficou claro.',
            'sample' => 'Claro! Vamos juntos: primeiro abra a Agenda, depois clique no atendimento e escolha "Remarcar". Ficou alguma dúvida?',
        ],
        'lobinho' => [
            'name' => 'Lobinho',
            'article' => 'o',
            'look' => 'Rajado de marrom e preto, olhos cor de mel, língua de fora.',
            'style' => 'Animado e leve: conversa de um jeito descontraído e pode usar um emoji de vez em quando, sem exagero.',
            'sample' => 'Bora remarcar! 🙂 Abre a Agenda, clica no atendimento e depois em "Remarcar". Rapidinho!',
        ],
        'ayla' => [
            'name' => 'Ayla',
            'article' => 'a',
            'look' => 'Caramelo, de focinho clarinho, olhos grandes e lenço rosado no pescoço.',
            'style' => 'Formal e cuidadosa: linguagem mais séria e precisa, sempre cordial.',
            'sample' => 'Para remarcar o atendimento, acesse a Agenda, selecione o atendimento desejado e utilize a opção "Remarcar".',
        ],
    ];

    public static function exists(string $id): bool
    {
        return isset(self::FOXES[$id]);
    }

    /**
     * @return array{id: string, name: string, article: string, look: string, style: string, sample: string, visual: string, avatar_url: string, avatars: array<string, string>}
     */
    public static function get(string $id, ?string $visual = null): array
    {
        $fox = self::FOXES[$id] ?? self::FOXES[self::DEFAULT];
        $id = isset(self::FOXES[$id]) ? $id : self::DEFAULT;
        $visual = isset(self::VISUALS[$visual]) ? $visual : self::DEFAULT_VISUAL;

        $avatars = [];
        foreach (array_keys(self::VISUALS) as $option) {
            $avatars[$option] = self::avatarUrl($id, $option);
        }

        return ['id' => $id] + $fox + ['visual' => $visual, 'avatar_url' => $avatars[$visual], 'avatars' => $avatars];
    }

    /**
     * Endereço absoluto pelo APP_URL: os sistemas mostram a imagem servida pela Foxy AI.
     */
    public static function avatarUrl(string $id, string $visual): string
    {
        return rtrim((string) config('app.url'), '/')."/core/assistants/{$visual}/{$id}.svg";
    }

    /**
     * @return array<string, string> visual → descrição
     */
    public static function visuals(): array
    {
        return self::VISUALS;
    }

    /**
     * @return list<array>
     */
    public static function all(): array
    {
        return array_map(fn (string $id) => self::get($id), array_keys(self::FOXES));
    }

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_keys(self::FOXES);
    }
}
