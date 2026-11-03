<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retrato das contas de cada produto (docs/08, seção 3): o produto envia, o Core guarda para o Foxy Admin.
 * Sem dados de contato nesta fase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('integration_id')->constrained();
            $table->json('metrics');
            $table->unsignedInteger('accounts_count')->default(0);
            $table->timestamp('taken_at');
            $table->timestamps();
            $table->index(['integration_id', 'taken_at']);
        });

        Schema::create('product_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('integration_id')->constrained();
            $table->string('external_ref', 120);
            $table->string('name');
            $table->string('plan', 120)->nullable();
            $table->string('status', 30);
            $table->decimal('mrr', 10, 2)->default(0);
            $table->timestamp('signed_up_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();
            $table->unique(['integration_id', 'external_ref']);
            $table->index(['integration_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_accounts');
        Schema::dropIfExists('product_snapshots');
    }
};
