<?php

namespace App\Ai\Http;

use App\Ai\Tasks\TaskRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/tasks — tarefa avulsa da Foxy AI (docs/12 §14): { task, input } → { output, usage }.
 */
class TaskController
{
    public function __invoke(Request $request, TaskRunner $runner): JsonResponse
    {
        $data = $request->validate([
            'task' => ['required', 'string', 'max:60'],
            'input' => ['required', 'array'],
        ]);

        return response()->json($runner->run($data['task'], $data['input']));
    }
}
