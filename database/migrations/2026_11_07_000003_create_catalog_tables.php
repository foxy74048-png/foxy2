<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foxy Commerce, fatia 3 (docs/09, seção 4): arquivos do Core e catálogo (categorias, produtos, variantes da
 * grade, kits e fotos). Valores em centavos (F6). A variante é a unidade vendável e estocável.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->string('owner_type', 60);
            $table->string('disk', 30);
            $table->string('path');
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->timestamps();
            $table->index(['organization_id', 'owner_type']);
        });

        Schema::create('commerce_categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->string('name', 80);
            $table->foreignUlid('parent_id')->nullable()->constrained('commerce_categories');
            $table->timestamps();
            $table->index(['organization_id', 'name']);
        });

        Schema::create('commerce_products', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('category_id')->nullable()->constrained('commerce_categories');
            $table->string('type', 10)->default('simple');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('brand', 80)->nullable();
            $table->string('status', 20)->default('active');
            $table->json('option_names')->nullable();
            $table->json('specs')->nullable(); // peso, medidas, NCM...
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });

        Schema::create('commerce_variants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('product_id')->constrained('commerce_products');
            $table->string('sku', 60);
            $table->json('options')->nullable();
            $table->unsignedInteger('price_cents');
            $table->unsignedInteger('cost_cents')->nullable();
            $table->string('gtin', 14)->nullable();
            $table->boolean('is_kit')->default(false);
            $table->string('status', 20)->default('active');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'sku']);
            $table->index('product_id');
        });

        Schema::create('commerce_kit_components', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('kit_variant_id')->constrained('commerce_variants');
            $table->foreignUlid('component_variant_id')->constrained('commerce_variants');
            $table->unsignedSmallInteger('quantity');
            $table->timestamps();
            $table->unique(['kit_variant_id', 'component_variant_id']);
            $table->index('component_variant_id');
        });

        Schema::create('commerce_product_images', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('product_id')->constrained('commerce_products');
            $table->foreignUlid('file_id')->constrained('files');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_product_images');
        Schema::dropIfExists('commerce_kit_components');
        Schema::dropIfExists('commerce_variants');
        Schema::dropIfExists('commerce_products');
        Schema::dropIfExists('commerce_categories');
        Schema::dropIfExists('files');
    }
};
