<?php

namespace App\Admin\Http;

use App\Platform\Products\ProductCommand;
use App\Platform\Products\ProductTicket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Suporte central: chamados de todos os produtos. A resposta vai ao produto como comando (support.reply), que
 * grava no chamado e avisa o cliente do jeito dele.
 */
class SupportAdminController
{
    public function index(Request $request): View
    {
        $status = $request->query('situacao', 'open');
        $status = $status === 'todos' || isset(ProductTicket::STATUSES[$status]) ? $status : 'open';

        return view('admin.support', [
            'tickets' => ProductTicket::with(['integration', 'account'])
                ->when($status !== 'todos', fn ($q) => $q->where('status', $status))
                ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")->latest('opened_at')
                ->paginate(30)->withQueryString(),
            'status' => $status,
            'counts' => ProductTicket::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            'pendingReplies' => ProductCommand::where('type', 'support.reply')->whereIn('status', ['pendente', 'enviado'])->get()
                ->keyBy(fn (ProductCommand $c) => $c->integration_id.'|'.($c->payload['ticket_id'] ?? '')),
        ]);
    }

    public function reply(Request $request, ProductTicket $ticket): RedirectResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'status' => ['required', Rule::in(['answered', 'closed'])],
        ]);

        ProductCommand::create([
            'integration_id' => $ticket->integration_id,
            'product_account_id' => $ticket->product_account_id,
            'type' => 'support.reply',
            'payload' => ['ticket_id' => $ticket->external_id, 'message' => $data['message'], 'status' => $data['status']],
            'created_by' => $request->user()->id,
        ]);

        return back()->with('status', 'Resposta enviada ao '.$ticket->integration->name.'. O cliente recebe em até 1 minuto.');
    }
}
