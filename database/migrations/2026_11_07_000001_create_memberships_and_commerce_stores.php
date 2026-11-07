<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Foxy Commerce, fatia 1 (docs/09, seções 2 e 11): login de clientes no Core, equipe por organização e a loja.
 * Aditiva. Quem já tem login hoje é da equipe Foxy (console e Admin), então vira staff.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_staff')->default(false);
        });
        DB::table('users')->update(['is_staff' => true]);

        // Identifica o sistema da própria foxy2 (ex.: "commerce") sem depender do nome.
        Schema::table('integrations', function (Blueprint $table) {
            $table->string('slug', 40)->nullable()->unique();
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->string('role', 20);
            $table->string('status', 20)->default('active');
            $table->foreignId('invited_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('invitations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->string('email');
            $table->string('role', 20);
            $table->string('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'email']);
        });

        Schema::create('commerce_stores', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->unique()->constrained();
            $table->string('slug', 60)->unique();
            $table->string('name');
            $table->string('whatsapp', 30)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_stores');
        Schema::dropIfExists('invitations');
        Schema::dropIfExists('memberships');
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_staff'));
    }
};
