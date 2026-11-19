<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foxy Finanças, fatia 7 (docs/12, seção 7): sócios da carteira do negócio, a parte de cada um, os movimentos
 * de sócio nos lançamentos (pró-labore, retirada, distribuição, aporte), o acerto entre sócios e o par da
 * retirada na carteira pessoal do sócio (linked_transaction_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_partners', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name', 80);
            // Parte em centésimos de % (5000 = 50%).
            $table->unsignedSmallInteger('share_bp')->default(0);
            // Onde a retirada entra na carteira pessoal do sócio (ele escolhe; vazio = não lança lá).
            $table->ulid('mirror_organization_id')->nullable();
            $table->ulid('mirror_account_id')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('finance_partner_settlements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('from_partner_id')->constrained('finance_partners');
            $table->foreignUlid('to_partner_id')->constrained('finance_partners');
            $table->bigInteger('amount_cents');
            $table->date('date');
            $table->string('note', 160)->nullable();
            $table->string('created_by', 30)->nullable();
            $table->timestamps();
        });

        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->ulid('partner_id')->nullable()->index();
            $table->string('partner_kind', 20)->nullable();
            $table->ulid('linked_transaction_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->dropIndex(['partner_id']);
            $table->dropColumn(['partner_id', 'partner_kind', 'linked_transaction_id']);
        });
        Schema::dropIfExists('finance_partner_settlements');
        Schema::dropIfExists('finance_partners');
    }
};
