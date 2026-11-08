<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foxy Commerce, fatia 7 (docs/09, seção 6): conexões com marketplaces (tokens criptografados), anúncios
 * criados no Foxy e a caixa de entrada dos avisos que os canais mandam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_channel_connections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->string('channel', 20);
            $table->string('external_account_id', 80);
            $table->string('name')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('status', 20)->default('active');
            $table->text('last_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->unique(['channel', 'external_account_id']);
            $table->index(['organization_id', 'channel']);
        });

        Schema::create('commerce_listings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('connection_id')->constrained('commerce_channel_connections');
            $table->foreignUlid('product_id')->constrained('commerce_products');
            $table->string('external_id', 80)->nullable();
            $table->string('title');
            $table->unsignedInteger('price_cents');
            $table->string('external_category', 80)->nullable();
            $table->json('attributes')->nullable();
            $table->string('status', 20)->default('draft');
            $table->text('last_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
            $table->index(['connection_id', 'external_id']);
        });

        Schema::create('commerce_listing_variants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('listing_id')->constrained('commerce_listings');
            $table->foreignUlid('variant_id')->constrained('commerce_variants');
            $table->string('external_variation_id', 80)->nullable();
            $table->integer('pushed_quantity')->nullable();
            $table->unique(['listing_id', 'variant_id']);
            $table->index('variant_id');
        });

        // Avisos chegam sem usuário: a loja é achada pela conta do vendedor no canal.
        Schema::create('commerce_channel_inbox', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('channel', 20);
            $table->string('external_account_id', 80)->nullable();
            $table->string('topic', 60);
            $table->string('resource', 255);
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->index(['processed_at', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_channel_inbox');
        Schema::dropIfExists('commerce_listing_variants');
        Schema::dropIfExists('commerce_listings');
        Schema::dropIfExists('commerce_channel_connections');
    }
};
