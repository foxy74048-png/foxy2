<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foxy Commerce, fatia 5 (docs/09, seção 4): clientes, pedidos de todos os canais e itens com snapshot de
 * nome, preço e custo (para o financeiro: receita, custo, taxas do canal e margem). Centavos (F6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_customers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('document', 20)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('state', 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'name']);
        });

        Schema::create('commerce_orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->unsignedInteger('number');
            $table->string('channel', 20);
            $table->string('external_id', 80)->nullable();
            $table->foreignUlid('customer_id')->nullable()->constrained('commerce_customers');
            $table->string('status', 20);
            $table->string('payment_method', 20)->nullable();
            $table->unsignedInteger('subtotal_cents');
            $table->unsignedInteger('discount_cents')->default(0);
            $table->unsignedInteger('shipping_cents')->default(0);
            $table->unsignedInteger('fee_cents')->default(0);
            $table->unsignedInteger('total_cents');
            $table->unsignedInteger('cost_cents')->default(0);
            $table->string('tracking_code', 60)->nullable();
            $table->text('notes')->nullable();
            $table->string('created_by', 60)->nullable();
            $table->timestamp('placed_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('reserved_until')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->unique(['organization_id', 'channel', 'external_id']);
            $table->index(['organization_id', 'placed_at']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('commerce_order_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('order_id')->constrained('commerce_orders');
            $table->foreignUlid('variant_id')->nullable()->constrained('commerce_variants');
            $table->foreignUlid('product_id')->nullable()->constrained('commerce_products');
            $table->string('sku', 60);
            $table->string('name');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('unit_price_cents');
            $table->unsignedInteger('unit_cost_cents')->default(0);
            $table->unsignedInteger('total_cents');
            $table->index('order_id');
            $table->index(['organization_id', 'variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_order_items');
        Schema::dropIfExists('commerce_orders');
        Schema::dropIfExists('commerce_customers');
    }
};
