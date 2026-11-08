<?php

namespace App\Commerce\Channels;

use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conta do lojista num marketplace. Tokens criptografados com a APP_KEY; nunca vão para a tela nem para o log.
 */
class ChannelConnection extends Model
{
    use BelongsToOrganization, HasUlids;

    public const STATUSES = ['active' => 'Conectado', 'needs_attention' => 'Precisa de atenção', 'disconnected' => 'Desconectado'];

    protected $table = 'commerce_channel_connections';

    protected $fillable = ['channel', 'external_account_id', 'name', 'access_token', 'refresh_token', 'token_expires_at', 'status', 'last_error', 'last_synced_at', 'settings'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class, 'connection_id');
    }

    public function label(): string
    {
        return config("commerce.channels.{$this->channel}.label", $this->channel);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function attention(string $error): void
    {
        $this->update(['status' => 'needs_attention', 'last_error' => mb_substr($error, 0, 2000)]);
    }
}
