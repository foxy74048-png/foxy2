<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foxy Finanças, fatia 1 (docs/12): a carteira (1:1 com a organização) — pessoal/casa ou do negócio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_wallets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->unique()->constrained();
            $table->string('kind', 20)->default('pessoal');
            $table->string('name', 80);
            $table->boolean('is_mei')->default(false);
            $table->unsignedTinyInteger('das_day')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_wallets');
    }
};
