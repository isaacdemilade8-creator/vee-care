<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Http\Resources\UserResource;
use App\Models\Message;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ChatController extends Controller
{
    public function contacts(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $query = User::query()->where('id', '!=', $user->id);

        if ($user->isRole('patient')) {
            $query->where('role', 'doctor');
        } elseif ($user->isRole('doctor')) {
            $query->where('role', 'patient')
                ->whereHas('patientAppointments', fn ($q) => $q->where('doctor_id', $user->id));
        } elseif ($user->isRole('admin')) {
            $query->whereNot('role', 'super_admin');
        }

        return UserResource::collection($query->orderBy('name')->paginate(25));
    }

    public function thread(Request $request, User $user): AnonymousResourceCollection
    {
        $this->authorizeConversation($request->user(), $user);

        $messages = Message::query()
            ->with(['sender', 'receiver'])
            ->where(function ($q) use ($request, $user) {
                $q->where('sender_id', $request->user()->id)->where('receiver_id', $user->id);
            })
            ->orWhere(function ($q) use ($request, $user) {
                $q->where('sender_id', $user->id)->where('receiver_id', $request->user()->id);
            })
            ->latest()
            ->paginate($request->integer('per_page', 30));

        return MessageResource::collection($messages);
    }

    public function send(Request $request, NotificationService $notifications): MessageResource
    {
        $data = $request->validate([
            'receiver_id' => ['required', 'exists:users,id'],
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $receiver = User::findOrFail($data['receiver_id']);
        $this->authorizeConversation($request->user(), $receiver);

        $message = Message::create([
            'sender_id' => $request->user()->id,
            'receiver_id' => $receiver->id,
            'body' => $data['body'],
        ]);

        $notifications->send(
            $receiver,
            'message.received',
            'New message',
            "{$request->user()->name}: ".str($message->body)->limit(90),
            ['messageId' => $message->id, 'senderId' => $request->user()->id]
        );

        $message->load(['sender', 'receiver']);

        broadcast(new MessageSent($message))->toOthers();

        return new MessageResource($message);
    }

    private function authorizeConversation(User $sender, User $receiver): void
    {
        abort_if($sender->id === $receiver->id, 422, 'You cannot message yourself.');

        if ($sender->isRole('super_admin', 'admin') || $receiver->isRole('super_admin', 'admin')) {
            return;
        }

        $isPatientDoctorPair = ($sender->isRole('patient') && $receiver->isRole('doctor'))
            || ($sender->isRole('doctor') && $receiver->isRole('patient'));

        abort_unless($isPatientDoctorPair, 403, 'Only patient-doctor conversations are allowed.');
    }
}
