<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foxy Commerce, fatia 6 (docs/09, seção 7): loja própria. Pagamento por fora, no Pix do próprio lojista (D-C4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_stores', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable();
            $table->string('pix_key', 77)->nullable();
            $table->string('pix_name', 25)->nullable();
            $table->string('pix_city', 15)->nullable();
            $table->text('payment_instructions')->nullable();
            $table->json('delivery')->nullable();
            $table->string('primary_color', 7)->nullable();
            $table->foreignUlid('logo_file_id')->nullable()->constrained('files');
            $table->text('about')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('commerce_stores', function (Blueprint $table) {
            $table->dropConstrainedForeignId('logo_file_id');
            $table->dropColumn(['published_at', 'pix_key', 'pix_name', 'pix_city', 'payment_instructions', 'delivery', 'primary_color', 'about']);
        });
    }
};
