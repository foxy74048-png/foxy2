<?php

namespace App\Platform\Context;

use LogicException;

/**
 * Guarda o ExecutionContext da requisição ou job atual (registrado como "scoped").
 */
class ContextStore
{
    private ?ExecutionContext $context = null;

    public function set(ExecutionContext $context): void
    {
        $this->context = $context;
    }

    public function has(): bool
    {
        return $this->context !== null;
    }

    public function get(): ExecutionContext
    {
        return $this->context ?? throw new LogicException('Operação sem ExecutionContext definido.');
    }

    public function forget(): void
    {
        $this->context = null;
    }

    /**
     * Executa $callback dentro de um contexto e restaura o anterior ao final.
     */
    public function run(ExecutionContext $context, callable $callback): mixed
    {
        $previous = $this->context;
        $this->context = $context;

        try {
            return $callback();
        } finally {
            $this->context = $previous;
        }
    }
}
