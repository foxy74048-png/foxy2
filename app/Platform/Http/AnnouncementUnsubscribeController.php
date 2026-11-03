<?php

namespace App\Platform\Http;

use App\Platform\Announcements\AnnouncementDelivery;
use App\Platform\Announcements\AnnouncementService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Página pública "não quero receber comunicados" (link assinado de cada mensagem). Confirmação por POST:
 * abrir o link (pré-visualização do WhatsApp, antivírus do e-mail) não descadastra ninguém sozinho.
 */
class AnnouncementUnsubscribeController
{
    public function show(AnnouncementDelivery $delivery): View
    {
        return view('announcements.unsubscribe', ['delivery' => $delivery, 'done' => false]);
    }

    public function confirm(Request $request, AnnouncementDelivery $delivery, AnnouncementService $service): View
    {
        $service->unsubscribe($delivery);

        return view('announcements.unsubscribe', ['delivery' => $delivery, 'done' => true]);
    }
}
