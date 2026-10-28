<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('host_profiles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->string('slug', 60);
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('tone')->nullable();
            $table->unsignedInteger('current_knowledge_version')->default(0);
            $table->unsignedInteger('current_tools_version')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'slug']);
        });

        Schema::create('knowledge_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('host_profile_id')->constrained();
            $table->unsignedInteger('version');
            $table->longText('content');
            $table->string('content_hash', 64);
            $table->timestamps();

            $table->unique(['host_profile_id', 'version']);
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('host_profile_id')->constrained();
            $table->string('user_ref', 120);
            // Fixados na abertura: a conversa só cresce, nunca é editada (docs/05-arquitetura.md, seção 5).
            $table->string('model', 60);
            $table->string('effort', 10);
            $table->longText('system_prompt');
            $table->json('tools');
            $table->json('allowed_tools');
            $table->unsignedInteger('knowledge_version');
            $table->unsignedInteger('tools_version');
            $table->string('status', 20)->default('awaiting_user');
            $table->timestamp('processing_started_at')->nullable();
            $table->json('pending_tool_calls')->nullable();
            $table->unsignedSmallInteger('tool_rounds')->default(0);
            $table->unsignedSmallInteger('user_messages')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->index(['organization_id', 'user_ref']);
        });

        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('conversation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('seq');
            $table->string('role', 20);
            $table->json('content');
            $table->timestamp('created_at');

            $table->unique(['conversation_id', 'seq']);
        });

        Schema::create('usage_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_ref', 120)->nullable();
            $table->string('model', 60);
            $table->unsignedInteger('input_tokens');
            $table->unsignedInteger('output_tokens');
            $table->unsignedInteger('cache_read_tokens');
            $table->unsignedInteger('cache_write_tokens');
            $table->decimal('cost_usd', 12, 6);
            $table->string('request_id', 40)->index();
            $table->timestamp('created_at');

            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_records');
        Schema::dropIfExists('conversation_messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('knowledge_packs');
        Schema::dropIfExists('host_profiles');
    }
};
