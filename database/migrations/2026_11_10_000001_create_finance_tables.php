<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Financeiro do Commerce: contas a receber (parcelas do crediário/fiado e avulsas), contas a pagar (com
 * repetição mensal), registro das mensagens de WhatsApp (cobranças e lembretes) e as preferências de
 * lembrete da loja. Dinheiro sempre em centavos. A Foxy não recebe nada: só registra e lembra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_receivables', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('customer_id')->nullable()->constrained('commerce_customers');
            $table->foreignUlid('order_id')->nullable()->constrained('commerce_orders');
            $table->string('description', 160);
            $table->unsignedBigInteger('amount_cents');
            $table->date('due_date');
            $table->unsignedSmallInteger('installment')->nullable();
            $table->unsignedSmallInteger('installments')->nullable();
            $table->string('status', 12)->default('aberto'); // aberto, recebido, cancelado
            $table->timestamp('received_at')->nullable();
            $table->unsignedBigInteger('received_cents')->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->text('notes')->nullable();
            $table->json('reminders')->nullable(); // etapa => quando o lembrete saiu
            $table->string('created_by', 30)->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status', 'due_date']);
        });

        Schema::create('commerce_payables', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->string('description', 160);
            $table->string('supplier', 120)->nullable();
            $table->string('category', 40)->default('outros');
            $table->unsignedBigInteger('amount_cents');
            $table->date('due_date');
            $table->string('status', 12)->default('aberto'); // aberto, pago, cancelado
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('paid_cents')->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->text('notes')->nullable();
            $table->ulid('series_id')->nullable(); // contas da mesma repetição/parcelamento
            $table->unsignedSmallInteger('installment')->nullable();
            $table->unsignedSmallInteger('installments')->nullable();
            $table->string('created_by', 30)->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status', 'due_date']);
        });

        Schema::create('commerce_whatsapp_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('customer_id')->nullable()->constrained('commerce_customers');
            $table->foreignUlid('receivable_id')->nullable()->constrained('commerce_receivables');
            $table->string('kind', 20); // cobranca, lembrete_antes, lembrete_dia, lembrete_atraso
            $table->string('phone', 30);
            $table->text('text');
            $table->string('status', 12); // enviado, falhou, manual
            $table->string('error', 255)->nullable();
            $table->string('created_by', 30)->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'created_at']);
        });

        Schema::table('commerce_stores', function (Blueprint $table) {
            $table->json('whatsapp_settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('commerce_stores', function (Blueprint $table) {
            $table->dropColumn('whatsapp_settings');
        });
        Schema::dropIfExists('commerce_whatsapp_messages');
        Schema::dropIfExists('commerce_payables');
        Schema::dropIfExists('commerce_receivables');
    }
};
