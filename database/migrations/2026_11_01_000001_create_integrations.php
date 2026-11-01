<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Integração (um sistema Foxy) → Organização (cliente final) → Usuário. Ver docs/07, seção 1.
 * Aditiva: colunas novas e anuláveis; o que já existe vira "uma integração por organização".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->foreignUlid('integration_id')->nullable()->constrained();
            $table->string('external_ref', 120)->nullable();
            $table->unique(['integration_id', 'external_ref']);
        });

        Schema::table('api_clients', function (Blueprint $table) {
            $table->foreignUlid('integration_id')->nullable()->constrained();
        });

        Schema::table('host_profiles', function (Blueprint $table) {
            $table->foreignUlid('integration_id')->nullable()->constrained();
            $table->unique(['integration_id', 'slug']);
        });

        Schema::table('audit_entries', function (Blueprint $table) {
            $table->foreignUlid('integration_id')->nullable()->constrained();
            $table->index(['integration_id', 'created_at']);
        });

        // Chave de sistema, perfil do sistema e auditoria de ações do sistema não têm organização.
        Schema::table('api_clients', fn (Blueprint $table) => $table->ulid('organization_id')->nullable()->change());
        Schema::table('host_profiles', fn (Blueprint $table) => $table->ulid('organization_id')->nullable()->change());
        Schema::table('audit_entries', fn (Blueprint $table) => $table->ulid('organization_id')->nullable()->change());

        foreach (DB::table('organizations')->whereNull('integration_id')->get() as $organization) {
            $integrationId = strtolower((string) Str::ulid());

            DB::table('integrations')->insert([
                'id' => $integrationId,
                'name' => $organization->name,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('organizations')->where('id', $organization->id)->update(['integration_id' => $integrationId]);
            DB::table('api_clients')->where('organization_id', $organization->id)->update(['integration_id' => $integrationId]);
            DB::table('audit_entries')->where('organization_id', $organization->id)->update(['integration_id' => $integrationId]);
            DB::table('host_profiles')->where('organization_id', $organization->id)
                ->update(['integration_id' => $integrationId, 'organization_id' => null]);
        }
    }

    public function down(): void
    {
        Schema::table('audit_entries', function (Blueprint $table) {
            $table->dropIndex(['integration_id', 'created_at']);
            $table->dropConstrainedForeignId('integration_id');
        });
        Schema::table('host_profiles', function (Blueprint $table) {
            $table->dropUnique(['integration_id', 'slug']);
            $table->dropConstrainedForeignId('integration_id');
        });
        Schema::table('api_clients', fn (Blueprint $table) => $table->dropConstrainedForeignId('integration_id'));
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropUnique(['integration_id', 'external_ref']);
            $table->dropConstrainedForeignId('integration_id');
            $table->dropColumn('external_ref');
        });
        Schema::dropIfExists('integrations');
    }
};
