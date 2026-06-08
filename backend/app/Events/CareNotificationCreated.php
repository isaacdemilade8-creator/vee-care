<?php

namespace App\Events;

use App\Models\CareNotification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CareNotificationCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public CareNotification $notification)
    {
        $this->notification->loadMissing('user');
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('users.'.$this->notification->user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->notification->id,
            'type' => $this->notification->type,
            'title' => $this->notification->title,
            'body' => $this->notification->body,
            'data' => $this->notification->data,
            'readAt' => $this->notification->read_at?->toISOString(),
            'createdAt' => $this->notification->created_at?->toISOString(),
        ];
    }
}
