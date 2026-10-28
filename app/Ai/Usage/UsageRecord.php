<?php

namespace App\Ai\Usage;

use App\Ai\Providers\ModelResponse;
use App\Platform\Context\ExecutionContext;
use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Uma chamada ao modelo: tokens e custo estimado. Não guarda texto.
 */
class UsageRecord extends Model
{
    use BelongsToOrganization, HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['cost_usd' => 'float'];
    }

    public static function fromResponse(ModelResponse $response, ?string $conversationId): self
    {
        $context = ExecutionContext::current();

        return self::create([
            'conversation_id' => $conversationId,
            'user_ref' => $context->onBehalfOf,
            'model' => $response->model,
            'input_tokens' => $response->inputTokens,
            'output_tokens' => $response->outputTokens,
            'cache_read_tokens' => $response->cacheReadTokens,
            'cache_write_tokens' => $response->cacheWriteTokens,
            'cost_usd' => self::estimateCost($response),
            'request_id' => $context->correlationId,
        ]);
    }

    /**
     * Custo estimado em dólares pela tabela de config/foxy.php (modelo sem preço = 0).
     */
    public static function estimateCost(ModelResponse $response): float
    {
        $price = config('foxy.ai.prices')[$response->model] ?? null;

        if (! $price) {
            return 0.0;
        }

        $cost = $response->inputTokens * $price['input']
            + $response->outputTokens * $price['output']
            + $response->cacheReadTokens * $price['cache_read']
            + $response->cacheWriteTokens * $price['cache_write'];

        return round($cost / 1_000_000, 6);
    }
}
