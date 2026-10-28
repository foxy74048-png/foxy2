<?php

namespace App\Ai\Assistant;

/**
 * Blocos tool_result no formato da API de Messages.
 */
class ToolResults
{
    public static function ok(string $toolUseId, string $content): array
    {
        return ['type' => 'tool_result', 'tool_use_id' => $toolUseId, 'content' => $content];
    }

    public static function error(string $toolUseId, string $message): array
    {
        return ['type' => 'tool_result', 'tool_use_id' => $toolUseId, 'content' => $message, 'is_error' => true];
    }
}
