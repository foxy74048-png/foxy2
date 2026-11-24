<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foxy Finanças, conquistas (pedido em 2026-11-24): pontos, conquistas e desafios do mês, por carteira.
 * `finance_rewards` = cada ponto ganho, com uma chave única por carteira (recalcular nunca duplica);
 * `finance_challenges` = desafios aceitos no mês (gastar até X numa categoria, guardar na meta, poucos gastinhos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_rewards', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->string('key', 120);
            $table->string('kind', 12);
            $table->integer('points')->default(0);
            $table->string('label', 160);
            $table->string('month', 7)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'key']);
            $table->index(['organization_id', 'kind']);
        });

        Schema::create('finance_challenges', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->string('month', 7);
            $table->string('kind', 20);
            $table->ulid('category_id')->nullable();
            $table->ulid('goal_id')->nullable();
            $table->bigInteger('target_cents')->default(0);
            $table->integer('target_count')->nullable();
            $table->string('label', 160);
            $table->string('status', 12)->default('ativo');
            $table->bigInteger('result')->nullable();
            $table->unsignedBigInteger('accepted_by')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'month']);
        });

        // Lembrete dos desafios pelo WhatsApp: só para quem ligar (em Meu perfil).
        if (Schema::hasTable('finance_whatsapp_links') && ! Schema::hasColumn('finance_whatsapp_links', 'challenge_reminders')) {
            Schema::table('finance_whatsapp_links', function (Blueprint $table) {
                $table->boolean('challenge_reminders')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('finance_whatsapp_links', 'challenge_reminders')) {
            Schema::table('finance_whatsapp_links', fn (Blueprint $table) => $table->dropColumn('challenge_reminders'));
        }
        Schema::dropIfExists('finance_challenges');
        Schema::dropIfExists('finance_rewards');
    }
};
