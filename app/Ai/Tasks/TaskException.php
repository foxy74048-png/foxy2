<?php

namespace App\Ai\Tasks;

use App\Ai\Assistant\AssistantException;

/**
 * Erro esperado de uma tarefa avulsa. Herda da do assistente: a API já transforma em resposta no formato único
 * (docs/05, seção 4) e não vai para o log.
 */
class TaskException extends AssistantException {}
