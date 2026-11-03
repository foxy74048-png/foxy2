<?php

namespace App\Platform\Announcements;

use App\Platform\Messaging\EmailSender;
use App\Platform\Messaging\WhatsAppSender;
use App\Platform\Products\ProductAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Quem recebe, como o texto fica para cada um e o envio aos poucos (a cada minuto, pelo agendador:
 * foxy:comunicados:enviar). Cada mensagem leva o link para sair da lista daquele canal.
 */
class AnnouncementService
{
    public function __construct(private EmailSender $email, private WhatsAppSender $whatsapp) {}

    /**
     * Contas do público (sem as removidas). Situações e planos vazios = todas.
     *
     * @param  array{integration_id?: ?string, statuses?: list<string>, plans?: list<string>}  $audience
     */
    public function accounts(array $audience): Builder
    {
        return ProductAccount::query()
            ->with('integration')
            ->where('status', '!=', 'removed')
            ->when($audience['integration_id'] ?? null, fn ($q, $id) => $q->where('integration_id', $id))
            ->when($audience['statuses'] ?? [], fn ($q, $s) => $q->whereIn('status', $s))
            ->when($audience['plans'] ?? [], fn ($q, $p) => $q->whereIn('plan', $p));
    }

    /**
     * Quantas pessoas recebem em cada canal (com contato e sem ter saído da lista).
     *
     * @return array{accounts: int, email: int, whatsapp: int}
     */
    public function counts(array $audience): array
    {
        return [
            'accounts' => $this->accounts($audience)->count(),
            'email' => $this->reachable($this->accounts($audience), 'email')->count(),
            'whatsapp' => $this->reachable($this->accounts($audience), 'whatsapp')->count(),
        ];
    }

    private function reachable(Builder $query, string $channel): Builder
    {
        return $channel === 'email'
            ? $query->whereNotNull('email')->where('email', '!=', '')->whereNull('email_opt_out_at')
            : $query->whereNotNull('whatsapp')->where('whatsapp', '!=', '')->whereNull('whatsapp_opt_out_at');
    }

    /** {nome} = pessoa de contato; {conta} = nome da conta; {produto} = produto. */
    public function render(string $text, ?ProductAccount $account): string
    {
        return strtr($text, [
            '{nome}' => trim((string) ($account?->contact_name ?: $account?->name ?: 'tudo bem')),
            '{conta}' => (string) ($account?->name ?? 'sua conta'),
            '{produto}' => (string) ($account?->integration?->name ?? 'Foxy'),
        ]);
    }

    /**
     * Começa o envio: põe na fila um envio por conta e canal (quem não tem contato ou saiu da lista fica de fora).
     */
    public function start(Announcement $announcement): int
    {
        $queued = 0;

        DB::transaction(function () use ($announcement, &$queued) {
            foreach ($announcement->channels() as $channel) {
                $this->reachable($this->accounts($announcement->audience), $channel)->each(function (ProductAccount $account) use ($announcement, $channel, &$queued) {
                    $delivery = AnnouncementDelivery::firstOrCreate(
                        ['announcement_id' => $announcement->id, 'product_account_id' => $account->id, 'channel' => $channel],
                        ['to' => $channel === 'email' ? $account->email : $account->whatsapp, 'status' => 'pendente'],
                    );
                    $queued += $delivery->wasRecentlyCreated ? 1 : 0;
                });
            }

            $announcement->update(['status' => 'enviando', 'started_at' => $announcement->started_at ?? now()]);
        });

        return $queued;
    }

    /**
     * Uma rodada do envio (chamada a cada minuto): até N e-mails e M WhatsApps, com pausa entre WhatsApps.
     *
     * @return array{email: int, whatsapp: int}
     */
    public function processBatch(bool $pause = true): array
    {
        $sent = ['email' => 0, 'whatsapp' => 0];
        $limits = ['email' => config('foxy.messaging.emails_per_minute'), 'whatsapp' => config('foxy.messaging.whatsapps_per_minute')];

        foreach ($limits as $channel => $limit) {
            $deliveries = AnnouncementDelivery::with(['announcement', 'account.integration'])
                ->where('channel', $channel)->where('status', 'pendente')
                ->whereHas('announcement', fn ($q) => $q->where('status', 'enviando'))
                ->orderBy('id')->limit($limit)->get();

            foreach ($deliveries as $i => $delivery) {
                if ($channel === 'whatsapp' && $pause && $i > 0) {
                    sleep(random_int(...config('foxy.messaging.whatsapp_pause_seconds')));
                }
                $sent[$channel] += $this->deliver($delivery) ? 1 : 0;
            }
        }

        // Comunicado sem nada na fila terminou.
        Announcement::where('status', 'enviando')
            ->whereDoesntHave('deliveries', fn ($q) => $q->where('status', 'pendente'))
            ->update(['status' => 'enviado', 'finished_at' => now()]);

        return $sent;
    }

    public function deliver(AnnouncementDelivery $delivery): bool
    {
        $account = $delivery->account;
        $optedOut = $delivery->channel === 'email' ? $account->email_opt_out_at : $account->whatsapp_opt_out_at;
        if ($optedOut) {
            $delivery->update(['status' => 'pulado', 'error' => 'Saiu da lista antes do envio.']);

            return false;
        }

        $error = $this->sendTo($delivery->channel, $delivery->to, $delivery->announcement, $account, $this->unsubscribeUrl($delivery));
        $delivery->update($error === null ? ['status' => 'enviado', 'sent_at' => now(), 'error' => null] : ['status' => 'falhou', 'error' => mb_substr($error, 0, 300)]);

        return $error === null;
    }

    /**
     * Teste para a própria equipe (não entra no histórico nem respeita a fila).
     *
     * @return array<string, ?string> canal → erro (null = enviado)
     */
    public function sendTest(Announcement $announcement, ?string $email, ?string $phone): array
    {
        $sample = $this->accounts($announcement->audience)->first();
        $result = [];
        if ($email && in_array('email', $announcement->channels(), true)) {
            $result['email'] = $this->sendTo('email', $email, $announcement, $sample, null, '[TESTE] ');
        }
        if ($phone && in_array('whatsapp', $announcement->channels(), true)) {
            $result['whatsapp'] = $this->sendTo('whatsapp', $phone, $announcement, $sample, null, '[TESTE] ');
        }

        return $result;
    }

    private function sendTo(string $channel, string $to, Announcement $announcement, ?ProductAccount $account, ?string $unsubscribe, string $prefix = ''): ?string
    {
        $text = $this->render($announcement->body, $account);

        if ($channel === 'email') {
            return $this->email->send($to, $prefix.$this->render((string) ($announcement->subject ?: $announcement->title), $account), $text, $unsubscribe);
        }

        $footer = "\n\n— Equipe Foxy".($unsubscribe ? "\nNão quer receber comunicados por WhatsApp? {$unsubscribe}" : '');

        return $this->whatsapp->send($to, $prefix.$text.$footer);
    }

    public function unsubscribeUrl(AnnouncementDelivery $delivery): string
    {
        return URL::signedRoute('announcements.unsubscribe', ['delivery' => $delivery->id]);
    }

    public function unsubscribe(AnnouncementDelivery $delivery): void
    {
        $field = $delivery->channel === 'email' ? 'email_opt_out_at' : 'whatsapp_opt_out_at';
        $delivery->account->forceFill([$field => $delivery->account->{$field} ?? now()])->save();
    }
}
