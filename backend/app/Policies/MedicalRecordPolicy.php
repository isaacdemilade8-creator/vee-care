<?php

namespace App\Policies;

use App\Models\MedicalRecord;
use App\Models\User;

class MedicalRecordPolicy
{
    public function view(User $user, MedicalRecord $medicalRecord): bool
    {
        if ($user->isRole('super_admin', 'admin') || $medicalRecord->patient_id === $user->id) {
            return true;
        }

        return $user->isRole('doctor')
            && $medicalRecord->patient->patientAppointments()->where('doctor_id', $user->id)->exists();
    }
}
