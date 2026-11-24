<?php

namespace App\Platform\Messaging;

/** Quem atende as mensagens que chegam numa instância da Foxy (config foxy.messaging.inbound: instância => classe). */
interface InboundHandler
{
    public function handle(InboundMessage $message): void;
}
