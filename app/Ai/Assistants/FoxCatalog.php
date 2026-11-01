<?php

namespace App\Ai\Assistants;

/**
 * As raposas da Foxy (docs/07, seção 4; nomes definidos em 2026-11-01). Mudam o nome, o visual e o
 * jeito de falar — as regras de segurança e a precisão são as mesmas para todas.
 */
class FoxCatalog
{
    public const DEFAULT = 'lud';

    private const FOXES = [
        'chico' => [
            'name' => 'Chico',
            'article' => 'o',
            'look' => 'Raposo ruivo clássico, de óculos.',
            'style' => 'Objetivo e direto: vai ao ponto, com respostas curtas e sem rodeios.',
            'sample' => 'Agenda › clique no atendimento › Remarcar › escolha o horário › Confirmar. Pronto.',
        ],
        'lud' => [
            'name' => 'Lud',
            'article' => 'a',
            'look' => 'Raposa ártica (branca), de cachecol.',
            'style' => 'Acolhedora e paciente: explica passo a passo, com calma, e confere se ficou claro.',
            'sample' => 'Claro! Vamos juntos: primeiro abra a Agenda, depois clique no atendimento e escolha "Remarcar". Ficou alguma dúvida?',
        ],
        'lobinho' => [
            'name' => 'Lobinho',
            'article' => 'o',
            'look' => 'Raposo cinza-prata, de fone de ouvido.',
            'style' => 'Animado e leve: conversa de um jeito descontraído e pode usar um emoji de vez em quando, sem exagero.',
            'sample' => 'Bora remarcar! 🙂 Abre a Agenda, clica no atendimento e depois em "Remarcar". Rapidinho!',
        ],
        'ayla' => [
            'name' => 'Ayla',
            'article' => 'a',
            'look' => 'Raposa dourada, de lenço no pescoço.',
            'style' => 'Formal e cuidadosa: linguagem mais séria e precisa, sempre cordial.',
            'sample' => 'Para remarcar o atendimento, acesse a Agenda, selecione o atendimento desejado e utilize a opção "Remarcar".',
        ],
    ];

    public static function exists(string $id): bool
    {
        return isset(self::FOXES[$id]);
    }

    /**
     * @return array{id: string, name: string, article: string, look: string, style: string, sample: string, avatar_url: string}
     */
    public static function get(string $id): array
    {
        $fox = self::FOXES[$id] ?? self::FOXES[self::DEFAULT];
        $id = isset(self::FOXES[$id]) ? $id : self::DEFAULT;

        // Endereço absoluto pelo APP_URL: os sistemas mostram a imagem servida pela Foxy AI.
        return ['id' => $id] + $fox + ['avatar_url' => rtrim((string) config('app.url'), '/')."/core/assistants/{$id}.svg"];
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
