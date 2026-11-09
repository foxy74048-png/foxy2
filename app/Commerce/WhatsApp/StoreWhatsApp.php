<?php

namespace App\Commerce\WhatsApp;

use App\Commerce\Catalog\Money;
use App\Commerce\Finance\Receivable;
use App\Commerce\Finance\WhatsAppMessage;
use App\Commerce\Storefront\PixCode;
use App\Commerce\Stores\Store;
use App\Platform\Context\ExecutionContext;
use App\Platform\Messaging\WhatsAppConnections;
use App\Platform\Messaging\WhatsAppSender;
use Illuminate\Support\Carbon;

/**
 * WhatsApp da loja (número dela, conectado por QR): cobranças e lembretes das contas a receber, com os
 * textos que a loja escolheu e o Pix dela. Sem conexão, a cobrança abre o WhatsApp de quem está usando
 * com a mensagem pronta (wa.me). Tudo fica no histórico (commerce_whatsapp_messages).
 */
class StoreWhatsApp
{
    public const TEMPLATES = [
        'cobranca' => "Olá, {cliente}! Aqui é da {loja}.\nPassando para lembrar: {descricao}, no valor de {valor}, com vencimento em {vencimento}.\n{pix}Qualquer dúvida, é só responder. Obrigada!",
        'lembrete_antes' => "Oi, {cliente}! Tudo bem? Aqui é da {loja}.\nLembrete: {descricao}, no valor de {valor}, vence em {vencimento}.\n{pix}Obrigada!",
        'lembrete_dia' => "Oi, {cliente}! Aqui é da {loja}.\nHoje vence: {descricao}, no valor de {valor}.\n{pix}Obrigada!",
        'lembrete_atraso' => "Oi, {cliente}, tudo bem? Aqui é da {loja}.\nConsta em aberto: {descricao}, no valor de {valor}, que venceu em {vencimento}. Consegue acertar?\n{pix}Se já pagou, pode desconsiderar. Obrigada!",
    ];

    public const PLACEHOLDERS = ['{cliente}', '{loja}', '{descricao}', '{valor}', '{vencimento}', '{pix}'];

    public function __construct(private WhatsAppConnections $connections) {}

    public static function instance(Store $store): string
    {
        return 'foxy_commerce_'.$store->organization_id;
    }

    /** Preferências da loja com os padrões. */
    public static function settings(Store $store): array
    {
        $saved = $store->whatsapp_settings ?? [];

        return [
            'reminders' => (bool) ($saved['reminders'] ?? false),
            'days_before' => (int) ($saved['days_before'] ?? 2),
            'on_due' => (bool) ($saved['on_due'] ?? true),
            'after_days' => array_values(array_filter(array_map('intval', $saved['after_days'] ?? [1, 7]), fn ($d) => $d > 0)),
            'templates' => array_merge(self::TEMPLATES, array_filter($saved['templates'] ?? [], 'filled')),
        ];
    }

    public function state(Store $store): array
    {
        return $this->connections->state(self::instance($store));
    }

    public function connect(Store $store): array
    {
        return $this->connections->connect(self::instance($store));
    }

    public function disconnect(Store $store): bool
    {
        return $this->connections->disconnect(self::instance($store));
    }

    public function isConnected(Store $store): bool
    {
        return $this->connections->isOpen(self::instance($store));
    }

    /** Texto pronto da mensagem para uma conta. */
    public function text(Store $store, Receivable $receivable, string $kind): string
    {
        $template = self::settings($store)['templates'][$kind] ?? self::TEMPLATES['cobranca'];
        $pix = '';
        if ($store->pixReady()) {
            $code = PixCode::payload($store->pix_key, $store->pix_name, $store->pix_city, $receivable->amount_cents, 'CONTA'.substr(preg_replace('/\W/', '', $receivable->id), -10));
            $pix = "Pix ({$store->pix_name}): {$store->pix_key}\nPix copia e cola:\n{$code}\n";
        }

        return trim(strtr($template, [
            '{cliente}' => trim(explode(' ', (string) $receivable->customer?->name)[0]) ?: 'tudo bem',
            '{loja}' => $store->name,
            '{descricao}' => trim(($receivable->installmentLabel() ? mb_strtolower($receivable->installmentLabel()).' — ' : '').$receivable->description),
            '{valor}' => Money::format($receivable->amount_cents),
            '{vencimento}' => $receivable->due_date->format('d/m/Y'),
            '{pix}' => $pix,
        ]));
    }

    /** Link do WhatsApp com a mensagem pronta (quando o número da loja não está conectado). */
    public static function manualLink(string $phone, string $text): ?string
    {
        $number = WhatsAppSender::normalize($phone);

        return strlen($number) >= 12 ? 'https://wa.me/'.$number.'?text='.rawurlencode($text) : null;
    }

    /**
     * Envia pelo número da loja e registra. Devolve o registro (status enviado/falhou).
     */
    public function send(Store $store, Receivable $receivable, string $kind): WhatsAppMessage
    {
        $phone = (string) $receivable->customer?->phone;
        $text = $this->text($store, $receivable, $kind);
        $error = $phone === '' ? 'Cliente sem WhatsApp cadastrado.' : $this->connections->send(self::instance($store), $phone, $text);

        return $this->record($receivable, $kind, $phone, $text, $error ? 'falhou' : 'enviado', $error);
    }

    public function record(Receivable $receivable, string $kind, string $phone, string $text, string $status, ?string $error = null): WhatsAppMessage
    {
        $context = ExecutionContext::current();

        return WhatsAppMessage::create([
            'customer_id' => $receivable->customer_id, 'receivable_id' => $receivable->id, 'kind' => $kind, 'phone' => $phone,
            'text' => $text, 'status' => $status, 'error' => $error,
            'created_by' => $context->principalType === ExecutionContext::PRINCIPAL_USER ? (string) $context->principalId : null,
        ]);
    }

    /**
     * Qual lembrete automático cabe hoje para esta conta (ou null): antes (N dias), no dia, ou atraso (cada dia configurado).
     *
     * @return array{0: string, 1: string}|null [etapa, tipo de mensagem]
     */
    public static function reminderStage(array $settings, Receivable $receivable, ?Carbon $today = null): ?array
    {
        $today ??= today();
        $diff = (int) $today->diffInDays($receivable->due_date, false); // >0 falta; <0 atrasou
        $sent = $receivable->reminders ?? [];

        $stage = match (true) {
            $settings['days_before'] > 0 && $diff === $settings['days_before'] => ['antes', 'lembrete_antes'],
            $diff === 0 && $settings['on_due'] => ['dia', 'lembrete_dia'],
            $diff < 0 && in_array(-$diff, $settings['after_days'], true) => ['atraso_'.(-$diff), 'lembrete_atraso'],
            default => null,
        };

        return $stage && ! isset($sent[$stage[0]]) ? $stage : null;
    }
}
