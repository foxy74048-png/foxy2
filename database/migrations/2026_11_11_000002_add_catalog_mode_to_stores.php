<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loja própria em modo catálogo: sem carrinho nem Pix, a cliente escolhe cor e tamanho e pede pelo WhatsApp
 * da loja. Opção de esconder da vitrine o que esgotou.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_stores', function (Blueprint $table) {
            $table->string('mode', 10)->default('loja'); // loja (carrinho + Pix), catalogo (pedido pelo WhatsApp)
            $table->boolean('hide_sold_out')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('commerce_stores', function (Blueprint $table) {
            $table->dropColumn(['mode', 'hide_sold_out']);
        });
    }
};
