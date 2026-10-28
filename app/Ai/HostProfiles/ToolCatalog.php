<?php

namespace App\Ai\HostProfiles;

use Illuminate\Database\Eloquent\Model;

/**
 * Versão do catálogo de tools de um anfitrião. Acessado sempre pelo HostProfile.
 */
class ToolCatalog extends Model
{
    protected $fillable = ['version', 'tools', 'content_hash'];

    protected function casts(): array
    {
        return ['tools' => 'array'];
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_column($this->tools, 'name');
    }

    /**
     * Definições no formato da API de Messages, só das tools permitidas, em ordem fixa (por nome).
     *
     * @param  list<string>  $allowed
     */
    public function definitionsFor(array $allowed): array
    {
        $tools = array_values(array_filter($this->tools, fn (array $tool) => in_array($tool['name'], $allowed, true)));
        usort($tools, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return array_map(fn (array $tool) => [
            'name' => $tool['name'],
            'description' => $tool['description'],
            'input_schema' => $tool['input_schema'],
        ], $tools);
    }

    public function find(string $name): ?array
    {
        foreach ($this->tools as $tool) {
            if ($tool['name'] === $name) {
                return $tool;
            }
        }

        return null;
    }
}
