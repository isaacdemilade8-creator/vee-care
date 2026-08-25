<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Practitioner duty and shift management.
     *
     * `shifts` are hospital-configurable working-period definitions (Morning
     * 08:00-16:00, Evening 16:00-00:00, Night 22:00-06:00 ...). They are
     * reusable across departments and wards and are owned by the tenant's
     * single organization; the tenant database itself is the isolation
     * boundary. `start_time` / `end_time` are stored as hospital-local wall
     * clock times; an end time earlier than the start time means the shift
     * spans midnight (22:00 -> 06:00), which the application layer normalises
     * into a positive duration when comparing shifts.
     *
     * `duty_assignments` record which practitioner works which shift on which
     * date (SHIFT defines the working period, DUTY ASSIGNMENT records who is
     * working it on a particular date). `department_id` is required so every
     * duty has an operational home; `ward_id` is optional because practitioners
     * may also work at department level. `practitioner_id` is a plain user
     * reference (role doctor/nurse/pharmacist/lab_technician, see Role::staff())
     * mirroring the admissions table's practitioner convention.
     *
     * "Currently on duty" is never stored: it is derived from `duty_date` +
     * the shift window + `status`, and the composite indexes below keep every
     * lookup (by practitioner, department, ward, status or shift) date-bounded
     * and index-backed instead of scanning history.
     *
     * Overlap protection cannot be expressed as a portable database exclusion
     * constraint on MySQL, so it is enforced transactionally by the service
     * layer: the practitioner's user row is locked (`lockForUpdate`) while the
     * candidate window is checked, serialising concurrent assignments for the
     * same practitioner.
     */
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->index();
            $table->string('name', 100);
            $table->time('start_time');
            $table->time('end_time');
            $table->string('status', 20)->default('active')->index();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('duty_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->index();
            $table->foreignId('practitioner_id')->constrained('users')->nullOnDelete();
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->constrained()->nullOnDelete();
            $table->foreignId('ward_id')->nullable()->constrained()->nullOnDelete();
            $table->date('duty_date')->index();
            $table->string('status', 20)->default('scheduled')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status', 'duty_date']);
            $table->index(['practitioner_id', 'duty_date']);
            $table->index(['department_id', 'duty_date']);
            $table->index(['ward_id', 'duty_date']);
            $table->index(['status', 'duty_date']);
            $table->index(['shift_id', 'duty_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duty_assignments');
        Schema::dropIfExists('shifts');
    }
};
