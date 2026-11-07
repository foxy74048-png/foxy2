<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assinatura de um produto por organização (docs/09, seção 2 e fatia 2). Genérica: outros produtos usam a mesma.
 * O cartão fica só no Stripe (Checkout e portal); aqui só ids e situação.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->string('product', 40);
            $table->string('plan', 40)->nullable();
            $table->string('status', 30);
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('founder_at')->nullable();
            $table->string('stripe_customer_id', 80)->nullable()->index();
            $table->string('stripe_subscription_id', 80)->nullable()->unique();
            $table->timestamps();
            $table->unique(['organization_id', 'product']);
        });

        // Eventos do Stripe já processados (o Stripe pode mandar o mesmo mais de uma vez).
        Schema::create('stripe_events', function (Blueprint $table) {
            $table->string('id', 80)->primary();
            $table->string('type', 80);
            $table->timestamp('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_events');
        Schema::dropIfExists('subscriptions');
    }
};
