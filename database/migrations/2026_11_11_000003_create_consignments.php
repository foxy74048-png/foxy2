<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consignação enviada (maleta/sacolinha): a loja deixa peças com uma cliente ou revendedora. As peças ficam
 * reservadas (saem do disponível, continuam no estoque). No acerto, o que ficou vira um pedido (canal
 * "consignação", com a comissão da revendedora como desconto) e o que voltou é liberado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_consignments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->unsignedInteger('number');
            $table->foreignUlid('customer_id')->constrained('commerce_customers');
            $table->string('kind', 12)->default('cliente'); // cliente, revendedora
            $table->decimal('commission_percent', 5, 2)->default(0);
            $table->date('sent_on');
            $table->date('due_on')->nullable();
            $table->string('status', 12)->default('aberta'); // aberta, acertada
            $table->foreignUlid('order_id')->nullable()->constrained('commerce_orders');
            $table->text('notes')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->string('created_by', 30)->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status', 'due_on']);
        });

        Schema::create('commerce_consignment_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('consignment_id')->constrained('commerce_consignments');
            $table->foreignUlid('variant_id')->constrained('commerce_variants');
            $table->string('name', 200);
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('price_cents');
            $table->unsignedInteger('returned')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_consignment_items');
        Schema::dropIfExists('commerce_consignments');
    }
};
