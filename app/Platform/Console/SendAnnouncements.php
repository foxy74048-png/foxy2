<?php

namespace App\Platform\Console;

use App\Platform\Announcements\AnnouncementService;
use Illuminate\Console\Command;

class SendAnnouncements extends Command
{
    protected $signature = 'foxy:comunicados:enviar';

    protected $description = 'Envia a próxima leva dos comunicados do Foxy Admin (e-mail e WhatsApp, aos poucos).';

    public function handle(AnnouncementService $service): int
    {
        $sent = $service->processBatch();
        $this->info("E-mails: {$sent['email']} · WhatsApps: {$sent['whatsapp']}");

        return self::SUCCESS;
    }
}
