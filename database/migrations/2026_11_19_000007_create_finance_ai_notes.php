<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foxy Finanças, fatia 8: as dicas da raposa sobre o mês ficam guardadas (uma por carteira e mês), para não gastar
 * crédito de novo a cada visita; só refaz quando a pessoa pede.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_ai_notes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->string('kind', 20)->default('tips');
            $table->string('month', 7);
            $table->json('content');
            $table->timestamps();
            $table->unique(['organization_id', 'kind', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_ai_notes');
    }
};
