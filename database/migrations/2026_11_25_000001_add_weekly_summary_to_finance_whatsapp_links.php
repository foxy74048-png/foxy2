<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foxy Finanças: resumo semanal opcional pelo WhatsApp (docs/12, seção 8). Só para quem ligar em Meu perfil.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('finance_whatsapp_links') && ! Schema::hasColumn('finance_whatsapp_links', 'weekly_summary')) {
            Schema::table('finance_whatsapp_links', function (Blueprint $table) {
                $table->boolean('weekly_summary')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('finance_whatsapp_links', 'weekly_summary')) {
            Schema::table('finance_whatsapp_links', fn (Blueprint $table) => $table->dropColumn('weekly_summary'));
        }
    }
};
