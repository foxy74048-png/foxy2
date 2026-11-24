<?php

namespace App\Platform\Messaging;

/** Uma mensagem de WhatsApp que chegou num número da Foxy (só o que os produtos precisam; nada é guardado). */
final class InboundMessage
{
    public const TEXT = 'text';

    public const IMAGE = 'image';

    public const AUDIO = 'audio';

    public const OTHER = 'other';

    public function __construct(
        public readonly string $instance,
        public readonly string $messageId,
        public readonly string $phone,
        public readonly string $kind,
        public readonly string $text = '',
    ) {}

    /**
     * Lê o evento "messages.upsert" da Evolution API (v2). Devolve null para o que não é mensagem de uma pessoa:
     * outros eventos, enviada pelo próprio número, grupo, status, ou sem número.
     */
    public static function fromEvolution(array $payload): ?self
    {
        $event = strtolower(str_replace('_', '.', (string) ($payload['event'] ?? '')));
        $data = $payload['data'] ?? null;
        if ($event !== 'messages.upsert' || ! is_array($data)) {
            return null;
        }
        if (array_is_list($data)) {
            $data = $data[0] ?? [];
        }
        $key = $data['key'] ?? [];
        if (($key['fromMe'] ?? false) === true || empty($key['id'])) {
            return null;
        }

        $jid = (string) ($key['remoteJid'] ?? '');
        // Contas novas do WhatsApp chegam como "@lid" (id interno); o número vem ao lado.
        if (str_ends_with($jid, '@lid')) {
            $jid = (string) ($key['remoteJidAlt'] ?? $key['senderPn'] ?? $data['senderPn'] ?? '');
        }
        if (! str_ends_with($jid, '@s.whatsapp.net')) {
            return null; // grupo (@g.us), status@broadcast, newsletter...
        }
        $phone = preg_replace('/\D/', '', strstr($jid, '@', true)) ?? '';
        if (strlen($phone) < 10) {
            return null;
        }

        $message = $data['message'] ?? [];
        $text = $message['conversation'] ?? $message['extendedTextMessage']['text'] ?? null;
        $kind = match (true) {
            is_string($text) && trim($text) !== '' => self::TEXT,
            isset($message['imageMessage']) => self::IMAGE,
            isset($message['audioMessage']) => self::AUDIO,
            default => self::OTHER,
        };
        if ($kind === self::IMAGE) {
            $text = $message['imageMessage']['caption'] ?? '';
        }

        return new self((string) ($payload['instance'] ?? ''), (string) $key['id'], $phone, $kind, trim((string) $text));
    }
}
