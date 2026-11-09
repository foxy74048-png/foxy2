<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foto por variação: uma foto pode valer para o produto todo (vazio) ou só para um valor da grade
 * (ex.: option_name "Cor", option_value "Nude" — todas as variantes nude usam essa foto).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_product_images', function (Blueprint $table) {
            $table->string('option_name', 30)->nullable();
            $table->string('option_value', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('commerce_product_images', function (Blueprint $table) {
            $table->dropColumn(['option_name', 'option_value']);
        });
    }
};
