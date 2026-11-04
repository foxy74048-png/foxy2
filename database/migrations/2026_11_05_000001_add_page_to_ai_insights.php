<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tela em que a pessoa estava quando a raposa anotou a ideia/dúvida (o Foxy Admin passa a ser o único lugar). Aditiva. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('last_page', 200)->nullable();
        });
        Schema::table('ai_insights', function (Blueprint $table) {
            $table->string('page', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_insights', fn (Blueprint $table) => $table->dropColumn('page'));
        Schema::table('conversations', fn (Blueprint $table) => $table->dropColumn('last_page'));
    }
};
