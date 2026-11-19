<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foxy Finanças, fatia 6b: metas de guardar dinheiro. Cada "guardei" / "tirei" é uma entrada da meta; quando a
 * meta tem conta e a pessoa diz de onde saiu, a entrada também vira uma transferência de verdade (transaction_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_goals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->string('name', 80);
            $table->bigInteger('target_cents');
            $table->date('deadline')->nullable();
            $table->ulid('account_id')->nullable();
            $table->bigInteger('start_cents')->default(0);
            $table->timestamp('achieved_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('finance_goal_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('goal_id')->constrained('finance_goals')->cascadeOnDelete();
            $table->bigInteger('amount_cents');
            $table->date('date');
            $table->string('note', 160)->nullable();
            $table->ulid('transaction_id')->nullable();
            $table->string('created_by', 30)->nullable();
            $table->timestamps();
            $table->index(['goal_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_goal_entries');
        Schema::dropIfExists('finance_goals');
    }
};
