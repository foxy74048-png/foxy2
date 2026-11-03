<?php

namespace App\Platform\Http;

use App\Platform\Context\ExecutionContext;
use App\Platform\Products\ProductCommand;
use App\Platform\Products\ProductTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * O produto busca o que o Foxy Admin pediu para ele e devolve o resultado (docs/08, seção 3.1).
 * Sempre só os comandos da própria integração (chave do sistema).
 */
class ProductCommandController
{
    public function index(): JsonResponse
    {
        $integrationId = ExecutionContext::current()->integration->getKey();

        $commands = DB::transaction(function () use ($integrationId) {
            $pending = ProductCommand::with('account')
                ->where('integration_id', $integrationId)
                ->where(fn ($q) => $q->where('status', 'pendente')
                    // pego e sem resposta há 10 min (produto caiu no meio): oferece de novo
                    ->orWhere(fn ($q) => $q->where('status', 'enviado')->where('picked_at', '<', now()->subMinutes(10))))
                ->orderBy('created_at')->limit(50)->lockForUpdate()->get();

            ProductCommand::whereIn('id', $pending->pluck('id'))->update(['status' => 'enviado', 'picked_at' => now()]);

            return $pending;
        });

        return response()->json(['data' => $commands->map(fn (ProductCommand $c) => [
            'id' => $c->id,
            'type' => $c->type,
            'account_ref' => $c->account?->external_ref,
            'payload' => $c->payload ?? (object) [],
        ])->values()]);
    }

    public function result(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['ok' => ['required', 'boolean'], 'message' => ['nullable', 'string', 'max:2000']]);

        $command = ProductCommand::where('integration_id', ExecutionContext::current()->integration->getKey())->findOrFail($id);
        $command->update(['status' => $data['ok'] ? 'feito' : 'falhou', 'result' => $data['message'] ?? null, 'done_at' => now()]);

        // Resposta de chamado: o Admin já mostra como respondido, antes do próximo retrato.
        if ($data['ok'] && $command->type === 'support.reply' && ($ticketId = $command->payload['ticket_id'] ?? null)) {
            ProductTicket::where('integration_id', $command->integration_id)->where('external_id', (string) $ticketId)
                ->update(['status' => $command->payload['status'] ?? 'answered', 'responded_at' => now()]);
        }

        return response()->json(['status' => $command->status]);
    }
}
