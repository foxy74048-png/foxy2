<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foxy Commerce, fatia 9 (docs/09, fase A do fiscal): nota fiscal emitida fora do Foxy (marketplace ou emissor
 * do lojista), ligada ao pedido. A emissão própria é a fase 4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_fiscal_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('order_id')->constrained('commerce_orders');
            $table->string('source', 20);
            $table->string('access_key', 44)->nullable();
            $table->string('number', 20)->nullable();
            $table->string('series', 5)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->unsignedInteger('total_cents')->nullable();
            $table->foreignUlid('xml_file_id')->nullable()->constrained('files');
            $table->timestamps();
            $table->unique(['organization_id', 'access_key']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_fiscal_documents');
    }
};
