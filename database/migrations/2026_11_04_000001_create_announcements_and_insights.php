<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 do Foxy Admin (docs/08): comunicados por e-mail/WhatsApp e ideias/dúvidas da raposa de todos os produtos.
 * Aditiva: contato do dono da conta (enviado pelo produto) e descadastro por canal (guardado só no Core).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_accounts', function (Blueprint $table) {
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('whatsapp', 30)->nullable();
            $table->timestamp('email_opt_out_at')->nullable();
            $table->timestamp('whatsapp_opt_out_at')->nullable();
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('title', 160);
            $table->string('channel', 20); // email, whatsapp, ambos
            $table->string('subject', 200)->nullable();
            $table->text('body');
            $table->json('audience'); // integration_id, statuses, plans
            $table->string('status', 20)->default('rascunho'); // rascunho, enviando, enviado, cancelado
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('announcement_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('announcement_id')->constrained('announcements')->cascadeOnDelete();
            $table->foreignUlid('product_account_id')->constrained('product_accounts')->cascadeOnDelete();
            $table->string('channel', 20); // email, whatsapp
            $table->string('to', 200);
            $table->string('status', 20)->default('pendente'); // pendente, enviado, falhou, pulado
            $table->string('error', 300)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['announcement_id', 'product_account_id', 'channel']);
            $table->index(['status', 'channel']);
        });

        Schema::create('ai_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('organization_id')->constrained();
            $table->string('kind', 20); // ideia (o sistema não tem) ou duvida (o manual não responde)
            $table->text('summary');
            $table->string('status', 20)->default('nova');
            $table->timestamps();
            $table->index(['kind', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_insights');
        Schema::dropIfExists('announcement_deliveries');
        Schema::dropIfExists('announcements');
        Schema::table('product_accounts', function (Blueprint $table) {
            $table->dropColumn(['contact_name', 'email', 'whatsapp', 'email_opt_out_at', 'whatsapp_opt_out_at']);
        });
    }
};
