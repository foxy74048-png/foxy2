<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foxy Finanças, fatia 6c: "Quem me deve / a quem eu devo". Pessoas (amigos, família) e os movimentos com cada
 * uma. Valor com sinal do ponto de vista da carteira: positivo = a pessoa te deve; negativo = você deve.
 * Dinheiro que sai ou entra numa conta vira transferência para a conta interna "Amigos e família" (não é gasto).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_contacts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->string('name', 80);
            $table->string('phone', 30)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'name']);
        });

        Schema::create('finance_person_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('contact_id')->constrained('finance_contacts');
            $table->string('kind', 20);
            $table->bigInteger('amount_cents')->default(0);
            $table->string('description', 160)->nullable();
            $table->date('date');
            $table->date('due_date')->nullable();
            $table->ulid('transaction_id')->nullable();
            $table->ulid('expense_transaction_id')->nullable();
            $table->ulid('group_id')->nullable()->index();
            $table->timestamp('returned_at')->nullable();
            $table->string('created_by', 30)->nullable();
            $table->timestamps();
            $table->index(['contact_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_person_entries');
        Schema::dropIfExists('finance_contacts');
    }
};
