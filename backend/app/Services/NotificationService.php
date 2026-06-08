<?php

namespace App\Services;

use App\Events\CareNotificationCreated;
use App\Models\CareNotification;
use App\Models\User;

class NotificationService
{
    public function send(User $user, string $type, string $title, string $body, array $data = []): CareNotification
    {
        $notification = CareNotification::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);

        broadcast(new CareNotificationCreated($notification))->toOthers();

        return $notification;
    }
}
