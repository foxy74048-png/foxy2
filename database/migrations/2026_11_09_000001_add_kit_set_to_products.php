<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conjunto (kit em que a cliente escolhe o tamanho de cada peça): guarda quais produtos formam o conjunto,
 * o nome de cada peça e a característica que precisa ser igual (ex.: Cor). Cada combinação vira uma variante-kit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_products', function (Blueprint $table) {
            $table->json('kit_set')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('commerce_products', function (Blueprint $table) {
            $table->dropColumn('kit_set');
        });
    }
};
