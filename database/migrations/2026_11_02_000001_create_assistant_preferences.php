<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raposa escolhida (e nome dado) pela conta e por cada usuário (docs/07, seção 4.2).
 * user_ref vazio = padrão da conta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('organization_id')->constrained();
            $table->string('user_ref', 120)->default('');
            $table->string('assistant', 30);
            $table->string('custom_name', 40)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'user_ref']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->string('assistant', 30)->nullable();
            $table->string('assistant_name', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['assistant', 'assistant_name']);
        });
        Schema::dropIfExists('assistant_preferences');
    }
};
