<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hospital structure foundation: departments -> wards -> rooms -> beds.
     *
     * Every row links to the tenant's single organization via the
     * BelongsToOrganization trait; the tenant database itself is the isolation
     * boundary. Wards may optionally belong to a department. Deletion of an
     * entity with dependents is blocked in the controller (deactivation is
     * preferred); the on-delete actions below are only a database-level safety
     * net for direct SQL.
     */
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('wards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->index();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('type', 100)->nullable();
            $table->unsignedInteger('capacity')->default(0);
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->index();
            $table->foreignId('ward_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('capacity')->default(1);
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
            $table->unique(['organization_id', 'ward_id', 'name']);
        });

        Schema::create('beds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->index();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->string('bed_number');
            $table->string('status', 20)->default('available')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->unique(['organization_id', 'room_id', 'bed_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beds');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('wards');
        Schema::dropIfExists('departments');
    }
};
