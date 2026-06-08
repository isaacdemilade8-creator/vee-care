<?php

namespace App\Events;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VideoSignalSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Appointment $appointment,
        public User $sender,
        public string $type,
        public array $payload,
    ) {
        //
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('video.appointments.'.$this->appointment->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'video.signal';
    }

    public function broadcastWith(): array
    {
        return [
            'appointmentId' => $this->appointment->id,
            'fromUserId' => $this->sender->id,
            'type' => $this->type,
            'payload' => $this->payload,
        ];
    }
}
