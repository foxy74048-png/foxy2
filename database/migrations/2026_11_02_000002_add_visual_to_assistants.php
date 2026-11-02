<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visual do desenho da raposa (facetas, linhas, neon), escolhido junto com a raposa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_preferences', fn (Blueprint $table) => $table->string('visual', 20)->nullable());
        Schema::table('conversations', fn (Blueprint $table) => $table->string('assistant_visual', 20)->nullable());
    }

    public function down(): void
    {
        Schema::table('conversations', fn (Blueprint $table) => $table->dropColumn('assistant_visual'));
        Schema::table('assistant_preferences', fn (Blueprint $table) => $table->dropColumn('visual'));
    }
};
