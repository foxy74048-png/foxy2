<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foxy Finanças, fatia 3 (docs/12, seção 5): importação de extrato, fatura e planilha antiga, copiada do Foxy
 * Studio. Cada arquivo vira uma importação; cada movimento, uma linha para revisar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_statement_imports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('account_id')->constrained('finance_accounts');
            $table->string('format', 10);
            $table->string('file_name')->nullable();
            $table->unsignedInteger('lines_count')->default(0);
            $table->unsignedInteger('new_lines_count')->default(0);
            $table->string('created_by', 30)->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('finance_statement_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('import_id')->constrained('finance_statement_imports');
            $table->foreignUlid('account_id')->constrained('finance_accounts');
            $table->date('date');
            $table->string('time', 8)->nullable();
            $table->string('description');
            $table->bigInteger('amount_cents');
            $table->string('dedupe_key', 64);
            $table->string('status', 20)->default('pending');
            $table->ulid('transaction_id')->nullable();
            $table->json('split_details')->nullable();
            $table->ulid('suggested_category_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'dedupe_key']);
            $table->index(['organization_id', 'status', 'date']);
            $table->index(['organization_id', 'account_id', 'date']);
        });

        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->ulid('statement_line_id')->nullable()->index();
        });

        Schema::table('finance_accounts', function (Blueprint $table) {
            // Colunas do último extrato em planilha desta conta, para a próxima vez já vir preenchido.
            $table->json('statement_mapping')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('finance_accounts', fn (Blueprint $table) => $table->dropColumn('statement_mapping'));
        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->dropIndex(['statement_line_id']);
            $table->dropColumn('statement_line_id');
        });
        Schema::dropIfExists('finance_statement_lines');
        Schema::dropIfExists('finance_statement_imports');
    }
};
