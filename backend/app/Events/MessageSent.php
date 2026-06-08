<?php

namespace App\Events;

use App\Http\Resources\MessageResource;
use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message)
    {
        $this->message->loadMissing(['sender', 'receiver']);
    }

    public function broadcastOn(): array
    {
        $first = min($this->message->sender_id, $this->message->receiver_id);
        $second = max($this->message->sender_id, $this->message->receiver_id);

        return [
            new PrivateChannel("chat.{$first}.{$second}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return (new MessageResource($this->message))->resolve();
    }
}
