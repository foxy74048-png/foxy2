<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remessas de fornecedor (ex.: comprovante de consignação da Miza): importadas do PDF ou do texto colado,
 * viram produtos/variantes (pelo código de barras) e entrada no estoque. Cada item é consignado (no
 * fechamento paga o vendido e devolve o resto) ou comprado (paga tudo no fechamento). No produto fica a
 * fornecedora e a origem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_supply_batches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->string('supplier', 120);
            $table->string('document_number', 40)->nullable();
            $table->date('received_on')->nullable();
            $table->date('closing_on')->nullable();
            $table->string('status', 12)->default('rascunho'); // rascunho, aberta, acertada, cancelada
            $table->string('price_basis', 10)->default('venda'); // venda: o preço do comprovante é o de venda; custo: é o custo
            $table->decimal('percent', 5, 2)->default(30); // comissão (venda) ou margem (custo)
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->json('groups')->nullable(); // rascunho: produtos agrupados para a prévia
            $table->text('notes')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->string('created_by', 30)->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });

        Schema::create('commerce_supply_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('batch_id')->constrained('commerce_supply_batches');
            $table->foreignUlid('variant_id')->nullable()->constrained('commerce_variants');
            $table->string('description', 200);
            $table->string('barcode', 20)->nullable();
            $table->string('mode', 12); // consignado, compra
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('document_price_cents');
            $table->unsignedBigInteger('cost_cents');
            $table->unsignedInteger('returned')->nullable();
            $table->unsignedInteger('sold')->nullable();
            $table->timestamps();
        });

        Schema::table('commerce_products', function (Blueprint $table) {
            $table->string('supplier', 120)->nullable();
            $table->string('supply_mode', 12)->nullable(); // consignado, compra (vazio = próprio)
        });
    }

    public function down(): void
    {
        Schema::table('commerce_products', function (Blueprint $table) {
            $table->dropColumn(['supplier', 'supply_mode']);
        });
        Schema::dropIfExists('commerce_supply_items');
        Schema::dropIfExists('commerce_supply_batches');
    }
};
