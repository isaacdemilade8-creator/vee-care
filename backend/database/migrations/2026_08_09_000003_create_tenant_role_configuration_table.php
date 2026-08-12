<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enabled tenant roles per hospital (control database).
     *
     * One row per (tenant, role) seeded from the role registry. Core roles are
     * required and always enabled; optional roles can be disabled per
     * hospital. Platform roles are never valid here — the configuration API
     * rejects them by validating against the tenant Role enum.
     */
    public function up(): void
    {
        Schema::create('tenant_role_configuration', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('role', 40)->index();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_role_configuration');
    }
};
