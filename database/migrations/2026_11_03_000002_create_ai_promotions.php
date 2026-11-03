<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promoções de IA (docs/08, seção 4): perguntas extras de brinde, controladas no Foxy Admin.
 * ai_promotion_grants garante uma entrega por promoção + cliente + período (aplicar de novo não repete).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_promotions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('integration_id')->nullable()->constrained();
            $table->string('name', 120);
            $table->string('audience', 20); // novas_contas, todas, contas
            $table->string('recurrence', 20); // uma_vez, mensal
            $table->unsignedInteger('credits');
            $table->unsignedSmallInteger('new_account_days')->nullable();
            $table->json('plans')->nullable();
            $table->json('account_refs')->nullable();
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->string('status', 20)->default('ativa'); // ativa, pausada, encerrada
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ai_promotion_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('ai_promotion_id')->constrained('ai_promotions')->cascadeOnDelete();
            $table->foreignUlid('organization_id')->constrained();
            $table->string('period', 10); // AAAA-MM (mensal) ou "unica"
            $table->unsignedInteger('credits');
            $table->timestamp('created_at')->nullable();
            $table->unique(['ai_promotion_id', 'organization_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_promotion_grants');
        Schema::dropIfExists('ai_promotions');
    }
};
