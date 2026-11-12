<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link da maleta (portal da revendedora/cliente): código secreto por pessoa, que a loja pode trocar a qualquer
 * momento (o link antigo para de funcionar). Só leitura: maletas, comissão, acertos e o que falta pagar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_customers', function (Blueprint $table) {
            $table->string('portal_token', 40)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('commerce_customers', function (Blueprint $table) {
            $table->dropUnique(['portal_token']);
            $table->dropColumn('portal_token');
        });
    }
};
