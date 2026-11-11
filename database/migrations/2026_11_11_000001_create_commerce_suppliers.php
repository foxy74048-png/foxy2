<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fornecedoras que a loja já recebeu (reconhecidas pelo CNPJ ou pelo nome que vem no documento), com o
 * jeito de lançar da última vez (consignado/comprado, comissão ou margem), e a memória das peças: cada
 * código de barras, código do fornecedor ou descrição já conferido aponta para a variação da loja. Assim a
 * próxima remessa já vem com o produto, a cor e o tamanho certos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_suppliers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->string('name', 120);
            $table->string('document_name', 120)->nullable(); // como o nome vem no comprovante/nota
            $table->string('document', 14)->nullable(); // CNPJ (ou CPF), só números
            $table->string('mode', 12)->default('consignado');
            $table->string('price_basis', 10)->default('venda');
            $table->decimal('percent', 5, 2)->default(30);
            $table->timestamps();
            $table->index(['organization_id', 'document']);
        });

        Schema::create('commerce_supplier_codes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('supplier_id')->constrained('commerce_suppliers');
            $table->string('code', 160); // bar:<código de barras>, cod:<código do fornecedor>, desc:<descrição>
            $table->foreignUlid('variant_id')->constrained('commerce_variants');
            $table->timestamps();
            $table->unique(['supplier_id', 'code']);
        });

        Schema::table('commerce_supply_batches', function (Blueprint $table) {
            $table->ulid('supplier_id')->nullable()->index();
            $table->string('source', 12)->nullable(); // comprovante, nfe
            $table->json('installments')->nullable(); // duplicatas da nota: [{due, cents}]
        });
    }

    public function down(): void
    {
        Schema::table('commerce_supply_batches', function (Blueprint $table) {
            $table->dropIndex(['supplier_id']);
            $table->dropColumn(['supplier_id', 'source', 'installments']);
        });
        Schema::dropIfExists('commerce_supplier_codes');
        Schema::dropIfExists('commerce_suppliers');
    }
};
