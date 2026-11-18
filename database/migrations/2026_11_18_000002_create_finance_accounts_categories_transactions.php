<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foxy Finanças, fatia 2 (docs/12, seção 4): contas e cartões, categorias (2 níveis) e lançamentos.
 * Dinheiro em centavos, sempre positivo (o tipo diz se entra ou sai).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->string('kind', 20);
            $table->string('name', 80);
            $table->string('institution', 80)->nullable();
            $table->bigInteger('opening_balance_cents')->default(0);
            $table->unsignedTinyInteger('closing_day')->nullable();
            $table->unsignedTinyInteger('due_day')->nullable();
            $table->bigInteger('limit_cents')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'archived_at']);
        });

        Schema::create('finance_categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->ulid('parent_id')->nullable();
            $table->string('kind', 20);
            $table->string('name', 60);
            $table->string('system_key', 40)->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'kind']);
            $table->index('parent_id');
        });

        Schema::create('finance_transactions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('account_id')->constrained('finance_accounts');
            $table->ulid('to_account_id')->nullable();
            $table->string('kind', 20);
            $table->bigInteger('amount_cents');
            $table->date('date');
            $table->string('description', 160);
            $table->ulid('category_id')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('efetivado');
            $table->string('invoice_month', 7)->nullable();
            $table->ulid('series_id')->nullable();
            $table->string('series_kind', 20)->nullable();
            $table->unsignedSmallInteger('installment')->nullable();
            $table->unsignedSmallInteger('installments')->nullable();
            $table->string('source', 20)->default('manual');
            $table->string('created_by', 30)->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->index(['organization_id', 'date']);
            $table->index(['organization_id', 'account_id', 'date']);
            $table->index(['organization_id', 'category_id']);
            $table->index('series_id');
            $table->index('to_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_transactions');
        Schema::dropIfExists('finance_categories');
        Schema::dropIfExists('finance_accounts');
    }
};
