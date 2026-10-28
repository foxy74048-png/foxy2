<?php

namespace App\Platform\Http;

use App\Platform\Context\ContextStore;
use Illuminate\Http\JsonResponse;

/**
 * Formato único de erro da API: { "error": { "code", "message", "request_id" } }.
 */
class ApiError
{
    public static function response(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        $store = app(ContextStore::class);

        $error = [
            'code' => $code,
            'message' => $message,
            'request_id' => $store->has() ? $store->get()->correlationId : null,
        ];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return new JsonResponse(['error' => $error], $status);
    }
}
