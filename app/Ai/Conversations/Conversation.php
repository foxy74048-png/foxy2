<?php

namespace App\Ai\Conversations;

use App\Ai\HostProfiles\HostProfile;
use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conversa de um usuário do anfitrião. Regras, tools e modelo ficam fixos na abertura e o
 * histórico só recebe inclusões: é o que mantém o cache e o raciocínio do modelo válidos.
 */
class Conversation extends Model
{
    use BelongsToOrganization, HasUlids;

    public const AWAITING_USER = 'awaiting_user';

    public const AWAITING_TOOLS = 'awaiting_tools';

    public const PROCESSING = 'processing';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return [
            'tools' => 'array',
            'allowed_tools' => 'array',
            'pending_tool_calls' => 'array',
            'processing_started_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function hostProfile(): BelongsTo
    {
        return $this->belongsTo(HostProfile::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class)->orderBy('seq');
    }

    /**
     * Histórico no formato do provedor, na ordem em que foi gravado.
     */
    public function history(): array
    {
        return $this->messages()->get()
            ->map(fn (ConversationMessage $m) => ['role' => $m->role, 'content' => $m->content])
            ->all();
    }

    /**
     * Grava novas mensagens no fim do histórico.
     */
    public function appendToHistory(array ...$messages): void
    {
        $seq = (int) $this->messages()->max('seq');

        foreach ($messages as $message) {
            $this->messages()->create([
                'seq' => ++$seq,
                'role' => $message['role'],
                'content' => $message['content'],
                'created_at' => now(),
            ]);
        }
    }

    /**
     * Marca a conversa como "em processamento" de forma atômica. Falha se outra requisição já estiver
     * processando (ou se o estado não for o esperado). Processamento travado há mais de 3 minutos é retomado:
     * como nada é gravado antes de o modelo responder, o estado anterior é deduzido de pending_tool_calls.
     */
    public function claim(string $expectedStatus): bool
    {
        $updated = static::query()
            ->whereKey($this->getKey())
            ->where(function ($query) use ($expectedStatus) {
                $query->where('status', $expectedStatus)
                    ->orWhere(function ($stale) use ($expectedStatus) {
                        $stale->where('status', self::PROCESSING)
                            ->where('processing_started_at', '<', now()->subMinutes(3));

                        $expectedStatus === self::AWAITING_TOOLS
                            ? $stale->whereNotNull('pending_tool_calls')
                            : $stale->whereNull('pending_tool_calls');
                    });
            })
            ->update(['status' => self::PROCESSING, 'processing_started_at' => now()]);

        if ($updated === 1) {
            $this->refresh();

            return true;
        }

        return false;
    }

    public function release(string $status, ?array $pendingToolCalls = null): void
    {
        $this->forceFill([
            'status' => $status,
            'processing_started_at' => null,
            'pending_tool_calls' => $pendingToolCalls,
        ])->save();
    }
}
