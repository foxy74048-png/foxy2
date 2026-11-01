<?php

namespace App\Ai\Assistants;

use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Raposa da conta (user_ref vazio) ou de um usuário, com o nome que a pessoa deu (opcional).
 */
class AssistantPreference extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'user_ref', 'assistant', 'custom_name'];

    /**
     * A raposa que vale para este usuário: a dele → a da conta → a padrão da Foxy.
     *
     * @return array{id: string, name: string, article: string, look: string, style: string, sample: string, avatar_url: string, custom: bool, source: string}
     */
    public static function resolveFor(?string $userRef): array
    {
        $preferences = self::whereIn('user_ref', array_filter(['', $userRef], fn ($v) => $v !== null))->get()->keyBy('user_ref');
        $chosen = ($userRef !== null ? $preferences->get($userRef) : null) ?? $preferences->get('');

        $fox = FoxCatalog::get($chosen?->assistant ?? FoxCatalog::DEFAULT);
        $custom = trim((string) $chosen?->custom_name);

        return array_merge($fox, [
            'name' => $custom !== '' ? $custom : $fox['name'],
            'custom' => $custom !== '',
            'source' => $chosen === null ? 'foxy' : ($chosen->user_ref === '' ? 'conta' : 'usuario'),
        ]);
    }
}
