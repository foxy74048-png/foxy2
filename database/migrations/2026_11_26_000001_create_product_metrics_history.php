<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Métricas do Foxy Admin (docs/08, seção 3.5): quando a conta ativou (1º uso de verdade), cada mudança de
 * situação ou de MRR e o fechamento de cada dia por produto. O histórico começa aqui: as contas que já existiam
 * ganham um registro de "início do histórico" com a situação de agora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_accounts', function (Blueprint $table) {
            $table->timestamp('activated_at')->nullable();
        });

        Schema::create('product_account_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('integration_id')->constrained();
            $table->foreignUlid('product_account_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->decimal('from_mrr', 10, 2)->default(0);
            $table->decimal('to_mrr', 10, 2)->default(0);
            $table->boolean('baseline')->default(false);
            $table->timestamp('occurred_at');
            $table->index(['integration_id', 'occurred_at']);
            $table->index(['product_account_id', 'occurred_at']);
        });

        Schema::create('product_metric_days', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('integration_id')->constrained();
            $table->date('day');
            $table->unsignedInteger('accounts')->default(0);
            $table->unsignedInteger('live')->default(0);
            $table->unsignedInteger('trial')->default(0);
            $table->unsignedInteger('paying')->default(0);
            $table->decimal('mrr', 12, 2)->default(0);
            $table->timestamps();
            $table->unique(['integration_id', 'day']);
        });

        $now = now();
        DB::table('product_accounts')->orderBy('id')->get(['id', 'integration_id', 'status', 'mrr'])
            ->chunk(500)->each(fn ($chunk) => DB::table('product_account_events')->insert($chunk->map(fn ($a) => [
                'id' => (string) Str::ulid(),
                'integration_id' => $a->integration_id,
                'product_account_id' => $a->id,
                'from_status' => null,
                'to_status' => $a->status,
                'from_mrr' => 0,
                'to_mrr' => $a->mrr,
                'baseline' => true,
                'occurred_at' => $now,
            ])->all()));
    }

    public function down(): void
    {
        Schema::dropIfExists('product_metric_days');
        Schema::dropIfExists('product_account_events');
        Schema::table('product_accounts', fn (Blueprint $table) => $table->dropColumn('activated_at'));
    }
};
