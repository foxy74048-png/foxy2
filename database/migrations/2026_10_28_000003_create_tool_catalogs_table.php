<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tool_catalogs', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('host_profile_id')->constrained();
            $table->unsignedInteger('version');
            $table->json('tools');
            $table->string('content_hash', 64);
            $table->timestamps();

            $table->unique(['host_profile_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_catalogs');
    }
};
