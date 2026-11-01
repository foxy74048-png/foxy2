<?php

namespace App\Ai\Plans;

/**
 * Um plano de IA (definido em config/foxy.php → ai.plans). Fala em faixas e capacidades, nunca em modelos.
 */
final readonly class AiPlan
{
    public const CAPABILITIES = ['suporte', 'erros', 'dados', 'escrita'];

    /**
     * @param  list<string>  $capabilities
     * @param  list<string>  $tiers
     */
    public function __construct(
        public string $code,
        public string $name,
        public int $credits,
        public array $capabilities,
        public array $tiers,
        public string $defaultTier,
    ) {}

    public static function find(?string $code): ?self
    {
        $config = $code ? (config('foxy.ai.plans')[$code] ?? null) : null;

        return $config ? new self($code, $config['name'], $config['credits'], $config['capabilities'], $config['tiers'], $config['default_tier']) : null;
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return array_map(fn (string $code) => self::find($code), array_keys(config('foxy.ai.plans')));
    }

    public function allows(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    public function allowsTier(string $tier): bool
    {
        return in_array($tier, $this->tiers, true);
    }

    public static function modelFor(string $tier): string
    {
        return config('foxy.ai.tiers')[$tier];
    }

    /**
     * Faixa de um modelo (para quem ainda pede pelo nome do modelo, ex.: console comparando).
     */
    public static function tierOfModel(string $model): ?string
    {
        return array_search($model, config('foxy.ai.tiers'), true) ?: null;
    }

    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'credits_per_month' => $this->credits,
            'capabilities' => $this->capabilities,
            'tiers' => $this->tiers,
            'default_tier' => $this->defaultTier,
        ];
    }
}
