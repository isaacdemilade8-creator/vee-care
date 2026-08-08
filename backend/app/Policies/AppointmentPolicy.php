<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;

class AppointmentPolicy
{
    public function view(User $user, Appointment $appointment): bool
    {
        return $user->isRole('hospital_admin')
            || $appointment->patient_id === $user->id
            || $appointment->doctor_id === $user->id;
    }

    public function update(User $user, Appointment $appointment): bool
    {
        return $user->isRole('hospital_admin') || $appointment->doctor_id === $user->id;
    }
}
