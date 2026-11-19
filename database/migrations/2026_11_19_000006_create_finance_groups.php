<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foxy Finanças, fatia 6d: despesas em grupo (viagem, república, festa). Pessoas do grupo, despesas (quem pagou)
 * com a parte de cada um, e os pagamentos entre eles. Fica separado dos lançamentos da carteira: no fim, o que é
 * da pessoa dona da carteira vai para "Quem me deve".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_groups', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->string('name', 80);
            // Link só de leitura para o grupo (sem login). Trocar o token invalida o link antigo.
            $table->string('token', 40)->unique();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('sent_to_debts_at')->nullable();
            $table->timestamps();
        });

        Schema::create('finance_group_members', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('group_id')->constrained('finance_groups')->cascadeOnDelete();
            $table->ulid('contact_id')->nullable();
            $table->string('name', 80);
            $table->boolean('is_me')->default(false);
            $table->timestamps();
        });

        Schema::create('finance_group_expenses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('group_id')->constrained('finance_groups')->cascadeOnDelete();
            $table->string('description', 160);
            $table->bigInteger('amount_cents');
            $table->date('date');
            $table->foreignUlid('paid_by')->constrained('finance_group_members');
            $table->string('split_mode', 10)->default('igual');
            $table->string('created_by', 30)->nullable();
            $table->timestamps();
        });

        Schema::create('finance_group_shares', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('expense_id')->constrained('finance_group_expenses')->cascadeOnDelete();
            $table->foreignUlid('member_id')->constrained('finance_group_members');
            $table->bigInteger('amount_cents');
        });

        Schema::create('finance_group_payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('group_id')->constrained('finance_groups')->cascadeOnDelete();
            $table->foreignUlid('from_member_id')->constrained('finance_group_members');
            $table->foreignUlid('to_member_id')->constrained('finance_group_members');
            $table->bigInteger('amount_cents');
            $table->date('date');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_group_payments');
        Schema::dropIfExists('finance_group_shares');
        Schema::dropIfExists('finance_group_expenses');
        Schema::dropIfExists('finance_group_members');
        Schema::dropIfExists('finance_groups');
    }
};
