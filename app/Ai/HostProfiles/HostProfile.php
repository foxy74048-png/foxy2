<?php

namespace App\Ai\HostProfiles;

use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Como a IA "entende" um sistema anfitrião: conhecimento e (depois) catálogo de tools, versionados.
 */
class HostProfile extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = ['slug', 'name', 'description', 'tone'];

    protected $attributes = ['current_knowledge_version' => 0, 'current_tools_version' => 0];

    public function knowledgePacks(): HasMany
    {
        return $this->hasMany(KnowledgePack::class);
    }

    public function toolCatalogs(): HasMany
    {
        return $this->hasMany(ToolCatalog::class);
    }

    public function currentTools(): ?ToolCatalog
    {
        return $this->toolCatalogs()->where('version', $this->current_tools_version)->first();
    }

    /**
     * Publica nova versão do catálogo (já normalizado por ToolSchema); catálogo igual não gera versão.
     */
    public function publishTools(array $tools): ToolCatalog
    {
        $hash = hash('sha256', json_encode($tools));
        $current = $this->currentTools();

        if ($current && $current->content_hash === $hash) {
            return $current;
        }

        $catalog = $this->toolCatalogs()->create([
            'version' => $this->current_tools_version + 1,
            'tools' => $tools,
            'content_hash' => $hash,
        ]);

        $this->forceFill(['current_tools_version' => $catalog->version])->save();

        return $catalog;
    }

    public function currentKnowledge(): ?KnowledgePack
    {
        return $this->knowledgePacks()->where('version', $this->current_knowledge_version)->first();
    }

    /**
     * Publica nova versão do conhecimento; conteúdo igual ao atual não gera versão.
     */
    public function publishKnowledge(string $content): KnowledgePack
    {
        $hash = hash('sha256', $content);
        $current = $this->currentKnowledge();

        if ($current && $current->content_hash === $hash) {
            return $current;
        }

        $pack = $this->knowledgePacks()->create([
            'version' => $this->current_knowledge_version + 1,
            'content' => $content,
            'content_hash' => $hash,
        ]);

        $this->forceFill(['current_knowledge_version' => $pack->version])->save();

        return $pack;
    }
}
