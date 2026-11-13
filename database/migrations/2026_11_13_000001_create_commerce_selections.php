<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seleções do catálogo: a lojista escolhe algumas peças (ex.: "Novidades da semana", "Tamanho G para a Bruna") e
 * manda um link só com elas, que mostra o estoque de agora e o botão de pedir pelo WhatsApp. Não reserva nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_selections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->string('title', 120);
            $table->string('token', 40)->unique(); // código secreto do link público
            $table->json('product_ids');
            $table->boolean('only_in_stock')->default(true); // esconde do link o que esgotou
            $table->boolean('active')->default(true); // desativada: o link para de abrir
            $table->timestamps();
            $table->index(['organization_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_selections');
    }
};
