<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plano de IA de cada cliente e créditos extras do mês (docs/07, seção 2). Os planos em si ficam em config/foxy.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('organization_id')->unique()->constrained();
            $table->string('plan', 40);
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('ai_credit_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('organization_id')->constrained();
            $table->string('period', 7);
            $table->unsignedInteger('credits');
            $table->string('reference', 120)->nullable();
            $table->timestamp('created_at');

            $table->index(['organization_id', 'period']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->string('tier', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', fn (Blueprint $table) => $table->dropColumn('tier'));
        Schema::dropIfExists('ai_credit_grants');
        Schema::dropIfExists('ai_subscriptions');
    }
};
