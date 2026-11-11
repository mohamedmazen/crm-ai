<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Custom roles a tenant defines for its own team.
     *
     * The three built-in roles (administrator, staff, viewer) stay in
     * config/client_permissions.php so existing users keep working without a
     * backfill; this table only holds roles the client creates itself.
     *
     * `permissions` is a JSON list of `module.action` keys from the catalogue.
     * Roles are always scoped to one client, so a slug only has to be unique
     * inside its tenant.
     */
    public function up(): void
    {
        Schema::create('client_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name', 128);
            $table->string('slug', 64);
            $table->string('description', 255)->nullable();
            $table->json('permissions');
            $table->timestamps();

            $table->unique(['client_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_roles');
    }
};
