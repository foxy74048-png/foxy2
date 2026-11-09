<?php

namespace App\Commerce\Http;

use App\Commerce\Finance\Receivable;
use App\Commerce\Finance\WhatsAppMessage;
use App\Commerce\Insights\Export;
use App\Commerce\WhatsApp\StoreWhatsApp;
use App\Platform\Audit\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * WhatsApp da loja: conectar o número (QR), lembretes automáticos, textos, histórico e a cobrança de uma conta.
 */
class WhatsAppController
{
    public function __construct(private StoreWhatsApp $whatsapp) {}

    public function show(Request $request): View|Response
    {
        $store = $request->attributes->get('commerce.store');
        $messages = WhatsAppMessage::with('customer')->latest()->limit(500);
        if ($format = Export::requested($request)) {
            return (new Export('Mensagens de WhatsApp'))
                ->section(null, ['Quando' => 'datetime', 'Cliente' => 'text', 'Telefone' => 'text', 'Tipo' => 'text', 'Situação' => 'text', 'Mensagem' => 'text'],
                    $messages->get()->map(fn (WhatsAppMessage $m) => [$m->created_at->timezone('America/Sao_Paulo'), $m->customer?->name, $m->phone,
                        WhatsAppMessage::KINDS[$m->kind] ?? $m->kind, (WhatsAppMessage::STATUSES[$m->status] ?? $m->status).($m->error ? ': '.$m->error : ''), $m->text]))
                ->download($format);
        }

        return view('commerce.whatsapp', [
            'state' => $this->whatsapp->state($store),
            'settings' => StoreWhatsApp::settings($store),
            'messages' => $messages->paginate(30),
        ]);
    }

    /** Gera o QR Code (a tela confere a conexão sozinha depois). */
    public function connect(Request $request): JsonResponse
    {
        $result = $this->whatsapp->connect($request->attributes->get('commerce.store'));
        Audit::record('commerce.whatsapp.connect', isset($result['error']) ? 'denied' : 'ok');

        return response()->json($result);
    }

    public function status(Request $request): JsonResponse
    {
        return response()->json($this->whatsapp->state($request->attributes->get('commerce.store')));
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $this->whatsapp->disconnect($request->attributes->get('commerce.store'));
        Audit::record('commerce.whatsapp.disconnected');

        return back()->with('status', 'WhatsApp desconectado. Os lembretes automáticos param até conectar de novo.');
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'reminders' => ['nullable', 'boolean'],
            'days_before' => ['nullable', 'integer', 'min:0', 'max:30'],
            'on_due' => ['nullable', 'boolean'],
            'after_days' => ['nullable', 'string', 'max:40'],
            'templates' => ['nullable', 'array'],
            'templates.*' => ['nullable', 'string', 'max:1500'],
        ], [], ['days_before' => 'dias antes', 'after_days' => 'dias depois']);

        $after = collect(preg_split('/[,;\s]+/', (string) ($data['after_days'] ?? '')))->map(fn ($d) => (int) $d)->filter(fn ($d) => $d > 0 && $d <= 90)->unique()->sort()->values()->take(5)->all();
        $templates = collect($data['templates'] ?? [])->only(array_keys(StoreWhatsApp::TEMPLATES))
            ->map(fn ($t) => trim((string) $t))->filter(fn ($t, $k) => $t !== '' && $t !== StoreWhatsApp::TEMPLATES[$k])->all();

        $store = $request->attributes->get('commerce.store');
        $store->update(['whatsapp_settings' => [
            'reminders' => $request->boolean('reminders'), 'days_before' => (int) ($data['days_before'] ?? 0), 'on_due' => $request->boolean('on_due'),
            'after_days' => $after, 'templates' => $templates,
        ]]);
        Audit::record('commerce.whatsapp.settings', subject: $store);

        return back()->with('status', 'Preferências do WhatsApp salvas.');
    }

    /**
     * Cobrança de uma conta. Número da loja conectado: envia daqui. Sem conexão: abre o WhatsApp de quem
     * está usando com a mensagem pronta (fica registrado como "aberto no WhatsApp").
     */
    public function charge(Request $request, Receivable $receivable): RedirectResponse
    {
        if (! $receivable->isOpen()) {
            throw ValidationException::withMessages(['status' => 'Esta conta não está em aberto.']);
        }
        $kind = $request->validate(['kind' => ['nullable', Rule::in(array_keys(StoreWhatsApp::TEMPLATES))]])['kind'] ?? 'cobranca';
        $store = $request->attributes->get('commerce.store');
        $receivable->loadMissing('customer');
        $phone = (string) $receivable->customer?->phone;
        if ($phone === '') {
            throw ValidationException::withMessages(['phone' => 'Esta conta não tem cliente com WhatsApp. Cadastre o telefone na ficha da cliente.']);
        }

        if ($this->whatsapp->isConnected($store)) {
            $message = $this->whatsapp->send($store, $receivable, $kind);

            return back()->with('status', $message->status === 'enviado' ? 'Cobrança enviada pelo WhatsApp da loja.' : 'A mensagem não saiu: '.$message->error);
        }

        $text = $this->whatsapp->text($store, $receivable, $kind);
        $link = StoreWhatsApp::manualLink($phone, $text) ?? throw ValidationException::withMessages(['phone' => 'O telefone da cliente parece incompleto.']);
        $this->whatsapp->record($receivable, $kind, $phone, $text, 'manual');

        return redirect()->away($link);
    }
}
