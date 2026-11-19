<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foxy Finanças, fatia 4 (docs/12, seção 6): "sempre que aparecer X, é a categoria Y". E, na linha do extrato,
 * de onde veio a sugestão (regra, da última vez, lista de lojas, planilha), para a tela explicar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_category_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->string('pattern', 80);
            $table->string('kind', 20);
            $table->ulid('category_id');
            $table->string('origin', 20)->default('pessoa');
            $table->unsignedInteger('hits')->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'kind', 'pattern']);
        });

        Schema::table('finance_statement_lines', function (Blueprint $table) {
            $table->string('suggestion_origin', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('finance_statement_lines', fn (Blueprint $table) => $table->dropColumn('suggestion_origin'));
        Schema::dropIfExists('finance_category_rules');
    }
};
