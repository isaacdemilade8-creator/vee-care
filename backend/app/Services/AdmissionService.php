<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\Bed;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Inpatient admission workflow.
 *
 * All writes that change bed occupancy run inside a database transaction. The
 * affected bed row is locked (`lockForUpdate`) while it is verified and
 * updated, so two concurrent admissions can never claim the same bed and a
 * discharge can never race with an admission on the same bed. The partial
 * unique indexes on the `admissions` table are the final database-level
 * backstop: a patient can never have more than one active admission even under
 * a lost race (e.g. two different beds, same patient).
 */
class AdmissionService
{
    public function admit(array $data): Admission
    {
        try {
            return DB::transaction(function () use ($data): Admission {
                /** @var Bed $bed */
                $bed = Bed::query()
                    ->whereKey($data['bed_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->assertBedAssignable($bed);
                $this->assertPatientAvailable($data['patient_id']);

                $admission = Admission::create([
                    'organization_id' => null,
                    'patient_id' => $data['patient_id'],
                    'department_id' => $data['department_id'] ?? null,
                    'ward_id' => $data['ward_id'] ?? null,
                    'room_id' => $data['room_id'] ?? null,
                    'bed_id' => $bed->id,
                    'practitioner_id' => $data['practitioner_id'] ?? null,
                    'status' => Admission::STATUS_ADMITTED,
                    'admitted_at' => $data['admitted_at'] ?? now(),
                    'reason' => $data['reason'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ]);

                $bed->update(['status' => 'occupied']);

                return $admission;
            });
        } catch (QueryException $e) {
            // The unique-index backstop fired (most likely a lost race where two
            // different beds were claimed for the same patient). Re-read the
            // committed state to report which invariant actually broke.
            if ((int) $e->getCode() !== 23000) {
                throw $e;
            }

            $bed = Bed::find($data['bed_id']);

            if ($bed && $bed->status !== 'available') {
                throw ValidationException::withMessages([
                    'bed_id' => ['This bed is already occupied.'],
                ]);
            }

            throw ValidationException::withMessages([
                'patient_id' => ['This patient already has an active admission.'],
            ]);
        }
    }

    public function discharge(Admission $admission, ?Carbon $dischargedAt = null): Admission
    {
        return DB::transaction(function () use ($admission, $dischargedAt): Admission {
            /** @var Admission $admission */
            $admission = Admission::query()
                ->whereKey($admission->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($admission->isDischarged()) {
                throw ValidationException::withMessages([
                    'status' => ['This admission has already been discharged.'],
                ]);
            }

            $bed = $admission->bed_id
                ? Bed::query()->whereKey($admission->bed_id)->lockForUpdate()->first()
                : null;

            $admission->update([
                'status' => Admission::STATUS_DISCHARGED,
                'discharged_at' => $dischargedAt ?? now(),
            ]);

            if ($bed) {
                $bed->update(['status' => 'available']);
            }

            return $admission;
        });
    }

    /**
     * Hard delete for administrative corrections. If the admission is still
     * active the bed is released back to `available` in the same transaction.
     */
    public function remove(Admission $admission): void
    {
        DB::transaction(function () use ($admission): void {
            /** @var Admission $admission */
            $admission = Admission::query()
                ->whereKey($admission->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $admission->isDischarged() && $admission->bed_id) {
                $bed = Bed::query()->whereKey($admission->bed_id)->lockForUpdate()->first();

                if ($bed) {
                    $bed->update(['status' => 'available']);
                }
            }

            $admission->delete();
        });
    }

    /**
     * Re-validated under the row lock: the bed must still be available and in
     * service. The chain/status checks performed by the controller happen
     * before this runs; this is the concurrency-safe second line.
     */
    protected function assertBedAssignable(Bed $bed): void
    {
        if (! $bed->is_active || $bed->status !== 'available') {
            throw ValidationException::withMessages([
                'bed_id' => ['This bed is no longer available.'],
            ]);
        }
    }

    protected function assertPatientAvailable(int $patientId): void
    {
        $active = Admission::query()
            ->where('patient_id', $patientId)
            ->where('status', Admission::STATUS_ADMITTED)
            ->exists();

        if ($active) {
            throw ValidationException::withMessages([
                'patient_id' => ['This patient already has an active admission.'],
            ]);
        }
    }
}
