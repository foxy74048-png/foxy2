<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Centralizar no Foxy Admin (docs/08, seção 3.1): comandos que o Admin pede e o produto busca e executa
 * (o Core nunca mexe no banco do produto), chamados de suporte de todos os produtos e mais dados por conta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_accounts', function (Blueprint $table) {
            $table->string('health', 20)->nullable();      // active, cooling, idle, never
            $table->json('problems')->nullable();          // [{key, label, detail, help}]
            $table->json('usage')->nullable();             // números de uso do produto (clientes, atendimentos...)
            $table->unsignedInteger('fox_credits')->nullable(); // raposa liberada à mão (perguntas/mês)
            $table->string('admin_url')->nullable();       // a conta no admin do produto
        });

        Schema::create('product_commands', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('integration_id')->constrained();
            $table->foreignUlid('product_account_id')->nullable()->constrained('product_accounts')->nullOnDelete();
            $table->string('type', 40);
            $table->json('payload')->nullable();
            $table->string('status', 20)->default('pendente'); // pendente, enviado, feito, falhou
            $table->text('result')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('picked_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();
            $table->index(['integration_id', 'status']);
        });

        Schema::create('product_tickets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('integration_id')->constrained();
            $table->foreignUlid('product_account_id')->nullable()->constrained('product_accounts')->nullOnDelete();
            $table->string('external_id', 60);
            $table->string('subject')->nullable();
            $table->string('category', 60)->nullable();
            $table->string('priority', 20)->nullable();
            $table->string('status', 20);                  // open, answered, closed
            $table->text('message');
            $table->text('admin_response')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['integration_id', 'external_id']);
            $table->index(['status', 'opened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_tickets');
        Schema::dropIfExists('product_commands');
        Schema::table('product_accounts', function (Blueprint $table) {
            $table->dropColumn(['health', 'problems', 'usage', 'fox_credits', 'admin_url']);
        });
    }
};
