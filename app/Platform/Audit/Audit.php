<?php

namespace App\Platform\Audit;

use App\Platform\Context\ExecutionContext;
use Illuminate\Database\Eloquent\Model;

class Audit
{
    /**
     * Registra uma ação com quem, em nome de quem e o correlation_id do contexto atual.
     */
    public static function record(string $action, string $result = 'ok', ?Model $subject = null, array $metadata = []): AuditEntry
    {
        $context = ExecutionContext::current();

        return AuditEntry::create([
            'organization_id' => $context->organization->getKey(),
            'principal_type' => $context->principalType,
            'principal_id' => $context->principalId,
            'on_behalf_of' => $context->onBehalfOf,
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'result' => $result,
            'metadata' => $metadata ?: null,
            'correlation_id' => $context->correlationId,
        ]);
    }
}
