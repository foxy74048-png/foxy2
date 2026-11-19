<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foxy Finanças, fatia 6: orçamento por categoria ("Delivery: até R$ 300 por mês"). month nulo = todo mês;
 * com mês, vale só naquele mês (e vence o de todo mês da mesma categoria).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_budgets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->ulid('category_id');
            $table->bigInteger('amount_cents');
            $table->string('month', 7)->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_budgets');
    }
};
