<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('status', 20)->default('active');
            $table->json('limits')->nullable();
            $table->timestamps();
        });

        Schema::create('api_clients', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->string('name');
            $table->string('key_prefix', 12);
            $table->string('key_hash', 64)->unique();
            $table->json('scopes');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('usage_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('organization_id')->constrained();
            $table->string('meter', 60);
            $table->string('subject_ref', 120)->default('');
            $table->string('period', 7);
            $table->unsignedBigInteger('quantity')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'meter', 'subject_ref', 'period']);
        });

        Schema::create('audit_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->string('principal_type', 20);
            $table->string('principal_id', 60)->nullable();
            $table->string('on_behalf_of', 120)->nullable();
            $table->string('action', 80);
            $table->string('subject_type', 60)->nullable();
            $table->string('subject_id', 60)->nullable();
            $table->string('result', 20);
            $table->json('metadata')->nullable();
            $table->string('correlation_id', 40)->index();
            $table->timestamp('created_at');

            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_entries');
        Schema::dropIfExists('usage_counters');
        Schema::dropIfExists('api_clients');
        Schema::dropIfExists('organizations');
    }
};
