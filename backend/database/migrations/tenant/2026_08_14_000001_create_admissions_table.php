<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Inpatient admissions and their bed assignment.
     *
     * An admission records that a patient occupies a bed inside the hospital
     * structure (department -> ward -> room -> bed). Every row links to the
     * tenant's single organization; the tenant database itself is the isolation
     * boundary. `department_id` / `ward_id` / `room_id` are denormalised for
     * fast filtering and are kept consistent with `bed_id` by the controller
     * (they must all resolve through the same chain). `practitioner_id` is the
     * optional attending user (doctor or nurse) and is intentionally just a
     * user reference so a future duty/roster schedule can drive it.
     *
     * Double-booking protection lives at three layers:
     *  1. the partial unique indexes below make it impossible for two rows to
     *     be active for the same bed or the same patient at the database level,
     *  2. the service assigns/releases beds inside transactions, locking the
     *     bed row while it is checked and updated,
     *  3. service-level validation returns a clean 422 before the database is
     *     touched in the common case.
     *
     * `status` supports exactly `admitted` and `discharged`; a discharged
     * admission can never become active again (a new admission row is created
     * instead), so admission history is preserved forever.
     */
    public function up(): void
    {
        Schema::create('admissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->index();
            $table->foreignId('patient_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ward_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bed_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('practitioner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('admitted')->index();
            $table->dateTime('admitted_at')->index();
            $table->dateTime('discharged_at')->nullable();
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['patient_id', 'status']);
            $table->index(['bed_id']);
            $table->unique(['bed_id', 'status'], 'admissions_active_bed_unique')->where('status', 'admitted');
            $table->unique(['patient_id', 'status'], 'admissions_active_patient_unique')->where('status', 'admitted');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admissions');
    }
};
