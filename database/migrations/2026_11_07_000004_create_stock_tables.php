<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foxy Commerce, fatia 4 (docs/09, seção 5): estoque único por variante. O saldo só muda por movimento
 * (só inclusão). Saldo negativo é permitido de propósito (venda dupla entre canais) e aparece em destaque.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_stock_levels', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('variant_id')->unique()->constrained('commerce_variants');
            $table->integer('on_hand')->default(0);
            $table->integer('reserved')->default(0);
            $table->unsignedInteger('min_stock')->nullable();
            $table->timestamps();
            $table->index('organization_id');
        });

        Schema::create('commerce_stock_movements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('variant_id')->constrained('commerce_variants');
            $table->string('type', 20);
            $table->integer('quantity');
            $table->integer('reserved_change')->default(0);
            $table->integer('on_hand_after');
            $table->ulid('order_id')->nullable();
            $table->string('reason', 255)->nullable();
            $table->string('user_id', 60)->nullable();
            $table->timestamp('created_at');
            $table->index(['variant_id', 'created_at']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_stock_movements');
        Schema::dropIfExists('commerce_stock_levels');
    }
};
