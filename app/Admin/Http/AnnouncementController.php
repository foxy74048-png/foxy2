<?php

namespace App\Admin\Http;

use App\Platform\Announcements\Announcement;
use App\Platform\Announcements\AnnouncementService;
use App\Platform\Integrations\Integration;
use App\Platform\Messaging\WhatsAppSender;
use App\Platform\Products\ProductAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Comunicados do Foxy Admin (docs/08, fase 2): escrever → conferir quem recebe e a prévia → testar → enviar.
 * O envio sai aos poucos (foxy:comunicados:enviar, a cada minuto); cada mensagem tem o link para sair da lista.
 */
class AnnouncementController
{
    public function __construct(private AnnouncementService $service) {}

    public function index(): View
    {
        return view('admin.announcements', [
            'announcements' => Announcement::withCount([
                'deliveries',
                'deliveries as sent_count' => fn ($q) => $q->where('status', 'enviado'),
                'deliveries as failed_count' => fn ($q) => $q->where('status', 'falhou'),
                'deliveries as pending_count' => fn ($q) => $q->where('status', 'pendente'),
            ])->latest()->get(),
            'integrations' => Integration::orderBy('name')->get(),
            'plans' => ProductAccount::whereNotNull('plan')->distinct()->orderBy('plan')->pluck('plan'),
            'optOuts' => ['email' => ProductAccount::whereNotNull('email_opt_out_at')->count(), 'whatsapp' => ProductAccount::whereNotNull('whatsapp_opt_out_at')->count()],
            'whatsappReady' => app(WhatsAppSender::class)->isConfigured(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $announcement = Announcement::create($data + ['status' => 'rascunho', 'created_by' => $request->user()->id]);

        return redirect()->route('admin.announcements.show', $announcement)->with('status', 'Rascunho salvo. Confira quem recebe e a prévia antes de enviar.');
    }

    public function show(Announcement $announcement): View
    {
        $sample = $this->service->accounts($announcement->audience)->first();

        return view('admin.announcement', [
            'announcement' => $announcement,
            'counts' => $this->service->counts($announcement->audience),
            'sample' => $sample,
            'preview' => $this->service->render($announcement->body, $sample),
            'previewSubject' => $this->service->render((string) ($announcement->subject ?: $announcement->title), $sample),
            'deliveries' => $announcement->deliveries()->with('account.integration')->latest('id')->limit(200)->get(),
            'byStatus' => $announcement->deliveries()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            'integrations' => Integration::orderBy('name')->get()->keyBy('id'),
            'whatsappReady' => app(WhatsAppSender::class)->isConfigured(),
        ]);
    }

    public function update(Request $request, Announcement $announcement): RedirectResponse
    {
        abort_unless($announcement->status === 'rascunho', 409, 'Só dá para editar rascunho.');
        $announcement->update($this->validated($request));

        return redirect()->route('admin.announcements.show', $announcement)->with('status', 'Rascunho atualizado.');
    }

    public function test(Request $request, Announcement $announcement): RedirectResponse
    {
        $data = $request->validate(['test_email' => ['nullable', 'email'], 'test_whatsapp' => ['nullable', 'string', 'max:30']]);
        $result = $this->service->sendTest($announcement, $data['test_email'] ?? null, $data['test_whatsapp'] ?? null);

        if ($result === []) {
            return back()->withErrors(['test' => 'Informe um e-mail ou WhatsApp do canal deste comunicado.']);
        }

        $lines = collect($result)->map(fn ($error, $channel) => ($channel === 'email' ? 'E-mail' : 'WhatsApp').': '.($error ?? 'enviado'))->implode(' · ');

        return back()->with(collect($result)->filter()->isEmpty() ? 'status' : 'warning', "Teste — {$lines}.");
    }

    public function send(Announcement $announcement): RedirectResponse
    {
        abort_unless($announcement->status === 'rascunho', 409, 'Este comunicado já foi enviado.');
        $queued = $this->service->start($announcement);

        return redirect()->route('admin.announcements.show', $announcement)->with('status', "Envio começou: {$queued} mensagem(ns) na fila. Saem aos poucos, a cada minuto.");
    }

    public function cancel(Announcement $announcement): RedirectResponse
    {
        if ($announcement->status === 'enviando' || $announcement->status === 'rascunho') {
            $announcement->deliveries()->where('status', 'pendente')->update(['status' => 'pulado', 'error' => 'Envio cancelado.']);
            $announcement->update(['status' => 'cancelado', 'finished_at' => now()]);
        }

        return redirect()->route('admin.announcements.show', $announcement)->with('status', 'Comunicado cancelado. O que já saiu não volta.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'channel' => ['required', Rule::in(array_keys(Announcement::CHANNELS))],
            'subject' => ['nullable', 'required_if:channel,email', 'required_if:channel,ambos', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:4000'],
            'integration_id' => ['nullable', Rule::exists('integrations', 'id')],
            'statuses' => ['nullable', 'array'],
            'statuses.*' => [Rule::in(array_keys(ProductAccount::STATUSES))],
            'plans' => ['nullable', 'array'],
            'plans.*' => ['string', 'max:120'],
        ]);

        return [
            'title' => $data['title'],
            'channel' => $data['channel'],
            'subject' => $data['subject'] ?? null,
            'body' => $data['body'],
            'audience' => [
                'integration_id' => $data['integration_id'] ?? null,
                'statuses' => array_values($data['statuses'] ?? []),
                'plans' => array_values($data['plans'] ?? []),
            ],
        ];
    }
}
