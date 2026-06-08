<?php

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('users.{userId}', function (User $user, int $userId): bool {
    return $user->id === $userId;
});

Broadcast::channel('chat.{firstUserId}.{secondUserId}', function (User $user, int $firstUserId, int $secondUserId): bool {
    return in_array($user->id, [$firstUserId, $secondUserId], true);
});

Broadcast::channel('video.appointments.{appointmentId}', function (User $user, int $appointmentId): bool {
    return Appointment::query()
        ->whereKey($appointmentId)
        ->where(function ($query) use ($user): void {
            $query->where('patient_id', $user->id)
                ->orWhere('doctor_id', $user->id);
        })
        ->exists();
});
