<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foxy Finanças, fatia 9: WhatsApp de entrada. `platform_inbound_messages` guarda só o id de cada mensagem recebida
 * (a Evolution pode mandar a mesma duas vezes) — sem o texto; some depois de 7 dias. `finance_whatsapp_links` liga o
 * número da pessoa à conta dela, confirmado por código.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_inbound_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('instance', 80);
            $table->string('message_id', 120);
            $table->timestamp('received_at');
            $table->unique(['instance', 'message_id']);
            $table->index('received_at');
        });

        Schema::create('finance_whatsapp_links', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('phone', 20)->index();
            $table->string('code_hash')->nullable();
            $table->timestamp('code_expires_at')->nullable();
            $table->unsignedSmallInteger('code_attempts')->default(0);
            $table->timestamp('verified_at')->nullable();
            $table->string('default_organization_id', 26)->nullable();
            $table->string('last_organization_id', 26)->nullable();
            $table->string('last_transaction_id', 26)->nullable();
            $table->timestamp('last_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_whatsapp_links');
        Schema::dropIfExists('platform_inbound_messages');
    }
};
