<?php

namespace App\Ai\HostProfiles;

use App\Ai\Assistant\InternalTools;

/**
 * Subconjunto simples de JSON Schema aceito nos catálogos de tools, e a validação das entradas
 * que o modelo gera. Sem dependência nova: só o necessário para tools de consulta.
 */
class ToolSchema
{
    public const MAX_TOOLS = 30;

    private const TYPES = ['string', 'integer', 'number', 'boolean', 'array'];

    private const PROPERTY_KEYS = ['type', 'description', 'enum', 'minimum', 'maximum', 'maxLength', 'items', 'maxItems'];

    /**
     * Normaliza o catálogo enviado pelo anfitrião ou devolve a lista de problemas.
     *
     * @return array{0: list<array>, 1: list<string>} [tools normalizadas, erros]
     */
    public static function normalizeCatalog(array $tools): array
    {
        $errors = [];
        $normalized = [];

        if (count($tools) > self::MAX_TOOLS) {
            return [[], ['No máximo '.self::MAX_TOOLS.' tools por catálogo.']];
        }

        foreach (array_values($tools) as $i => $tool) {
            $label = is_array($tool) && is_string($tool['name'] ?? null) ? $tool['name'] : "#{$i}";

            if (! is_array($tool)) {
                $errors[] = "{$label}: formato inválido.";

                continue;
            }

            $name = $tool['name'] ?? null;
            if (! is_string($name) || ! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name)) {
                $errors[] = "{$label}: nome deve ter letras minúsculas, números e _ (até 64).";
            } elseif (InternalTools::isInternal($name)) {
                $errors[] = "{$label}: o prefixo foxy_ é reservado.";
            } elseif (in_array($name, array_column($normalized, 'name'), true)) {
                $errors[] = "{$label}: nome repetido.";
            }

            if (! is_string($tool['description'] ?? null) || trim($tool['description']) === '' || mb_strlen($tool['description']) > 1000) {
                $errors[] = "{$label}: descrição obrigatória (até 1000 caracteres).";
            }

            if (($tool['risk'] ?? null) !== 'read') {
                $errors[] = "{$label}: nesta fase só são aceitas tools de leitura (risk = \"read\").";
            }

            if (isset($tool['permission']) && (! is_string($tool['permission']) || mb_strlen($tool['permission']) > 100)) {
                $errors[] = "{$label}: permission deve ser texto.";
            }

            [$schema, $schemaErrors] = self::normalizeObjectSchema($tool['input_schema'] ?? null);
            foreach ($schemaErrors as $error) {
                $errors[] = "{$label}: {$error}";
            }

            $normalized[] = [
                'name' => $name,
                'description' => $tool['description'] ?? '',
                'input_schema' => $schema,
                'permission' => $tool['permission'] ?? null,
                'risk' => 'read',
            ];
        }

        return [$errors === [] ? $normalized : [], $errors];
    }

    /**
     * @return list<string> problemas da entrada (vazio = válida)
     */
    public static function inputErrors(array $schema, mixed $input): array
    {
        if (! is_array($input) || ($input !== [] && array_is_list($input))) {
            return ['A entrada deve ser um objeto.'];
        }

        $errors = [];
        $properties = $schema['properties'] ?? [];

        foreach (array_keys($input) as $key) {
            if (! array_key_exists($key, $properties)) {
                $errors[] = "Campo desconhecido: {$key}.";
            }
        }

        foreach ($schema['required'] ?? [] as $key) {
            if (! array_key_exists($key, $input)) {
                $errors[] = "Campo obrigatório ausente: {$key}.";
            }
        }

        foreach ($input as $key => $value) {
            if (isset($properties[$key])) {
                array_push($errors, ...self::valueErrors($key, $properties[$key], $value));
            }
        }

        return $errors;
    }

    private static function normalizeObjectSchema(mixed $schema): array
    {
        if (! is_array($schema) || ($schema['type'] ?? null) !== 'object') {
            return [null, ['input_schema deve ser {"type": "object", "properties": {...}}.']];
        }

        $errors = [];
        $properties = $schema['properties'] ?? [];

        if (! is_array($properties) || ($properties !== [] && array_is_list($properties)) || count($properties) > 20) {
            return [null, ['properties deve ser um objeto com até 20 campos.']];
        }

        foreach ($properties as $key => $property) {
            if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/', (string) $key)) {
                $errors[] = "campo \"{$key}\": nome inválido.";

                continue;
            }

            array_push($errors, ...array_map(fn ($e) => "campo \"{$key}\": {$e}", self::propertyErrors($property, allowArray: true)));
        }

        $required = $schema['required'] ?? [];
        if (! is_array($required) || array_diff($required, array_keys($properties)) !== []) {
            $errors[] = 'required deve listar só campos existentes.';
        }

        if (array_key_exists('additionalProperties', $schema) && $schema['additionalProperties'] !== false) {
            $errors[] = 'additionalProperties só pode ser false.';
        }

        $normalized = ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
        if ($required !== []) {
            $normalized['required'] = array_values($required);
        }

        return [$normalized, $errors];
    }

    private static function propertyErrors(mixed $property, bool $allowArray): array
    {
        if (! is_array($property)) {
            return ['definição inválida.'];
        }

        $errors = [];
        $unknown = array_diff(array_keys($property), self::PROPERTY_KEYS);
        if ($unknown !== []) {
            $errors[] = 'palavras não suportadas: '.implode(', ', $unknown).'.';
        }

        $type = $property['type'] ?? null;
        if (! in_array($type, self::TYPES, true) || ($type === 'array' && ! $allowArray)) {
            return [...$errors, 'type deve ser '.implode(', ', $allowArray ? self::TYPES : array_diff(self::TYPES, ['array'])).'.'];
        }

        if (isset($property['enum']) && (! is_array($property['enum']) || $property['enum'] === [] || ! array_is_list($property['enum']))) {
            $errors[] = 'enum deve ser uma lista não vazia.';
        }

        if ($type === 'array') {
            array_push($errors, ...array_map(fn ($e) => "items: {$e}", self::propertyErrors($property['items'] ?? null, allowArray: false)));
        }

        return $errors;
    }

    private static function valueErrors(string $key, array $property, mixed $value): array
    {
        $type = $property['type'];

        $typeOk = match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
        };

        if (! $typeOk) {
            return ["{$key}: deve ser {$type}."];
        }

        $errors = [];

        if (isset($property['enum']) && ! in_array($value, $property['enum'], true)) {
            $errors[] = "{$key}: valor fora da lista permitida (".implode(', ', array_map('json_encode', $property['enum'])).').';
        }

        if (isset($property['minimum']) && is_numeric($value) && $value < $property['minimum']) {
            $errors[] = "{$key}: mínimo {$property['minimum']}.";
        }

        if (isset($property['maximum']) && is_numeric($value) && $value > $property['maximum']) {
            $errors[] = "{$key}: máximo {$property['maximum']}.";
        }

        if (isset($property['maxLength']) && is_string($value) && mb_strlen($value) > $property['maxLength']) {
            $errors[] = "{$key}: até {$property['maxLength']} caracteres.";
        }

        if ($type === 'array') {
            if (isset($property['maxItems']) && count($value) > $property['maxItems']) {
                $errors[] = "{$key}: até {$property['maxItems']} itens.";
            }

            foreach ($value as $i => $item) {
                array_push($errors, ...self::valueErrors("{$key}[{$i}]", $property['items'], $item));
            }
        }

        return $errors;
    }
}
