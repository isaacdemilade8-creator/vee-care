<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UrgentCareRequestResource;
use App\Models\UrgentCareRequest;
use App\Models\User;
use App\Services\AuditService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class UrgentCareRequestController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $query = UrgentCareRequest::query()
            ->with(['patient', 'assignee'])
            ->latest();

        if ($user->isRole('patient')) {
            $query->where('patient_id', $user->id);
        }

        $query
            ->when($request->string('status')->toString(), fn ($q, $status) => $q->where('status', $status))
            ->when($request->string('severity')->toString(), fn ($q, $severity) => $q->where('severity', $severity));

        return UrgentCareRequestResource::collection($query->paginate($request->integer('per_page', 10)));
    }

    public function store(Request $request, NotificationService $notifications, AuditService $audit): UrgentCareRequestResource
    {
        $data = $request->validate([
            'symptoms' => ['required', 'array', 'min:1', 'max:8'],
            'symptoms.*' => ['required', 'string', 'max:80'],
            'severity' => ['required', Rule::in(['low', 'moderate', 'high', 'critical'])],
            'preferred_channel' => ['required', Rule::in(['chat', 'video', 'phone'])],
            'message' => ['nullable', 'string', 'max:800'],
        ]);

        $patient = $request->user();
        $priority = $this->priorityFor($data['severity']);
        $assignee = $this->selectAssignee($patient, $data['severity']);

        $triage = UrgentCareRequest::create([
            'organization_id' => null,
            'branch_id' => null,
            'patient_id' => $patient->id,
            'assigned_to' => $assignee?->id,
            'severity' => $data['severity'],
            'priority' => $priority,
            'preferred_channel' => $data['preferred_channel'],
            'queue_name' => $this->queueNameFor($data['severity']),
            'status' => $assignee ? 'assigned' : 'queued',
            'symptoms' => array_values($data['symptoms']),
            'message' => $data['message'] ?? null,
            'assigned_at' => $assignee ? now() : null,
        ]);

        $triage->load(['patient', 'assignee']);
        $this->notifyCareTeam($triage, $notifications);

        $audit->record($request, 'urgent_care.requested', $triage, [
            'severity' => $triage->severity,
            'preferred_channel' => $triage->preferred_channel,
            'assigned_to' => $triage->assigned_to,
        ]);

        return new UrgentCareRequestResource($triage);
    }

    public function update(Request $request, UrgentCareRequest $urgentCareRequest, NotificationService $notifications, AuditService $audit): UrgentCareRequestResource
    {
        $user = $request->user();
        abort_unless($user->isRole('super_admin', 'admin', 'doctor', 'nurse'), 403);

        $data = $request->validate([
            'status' => ['required', Rule::in(['queued', 'assigned', 'in_progress', 'resolved', 'cancelled'])],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $assigneeId = $data['assigned_to'] ?? $urgentCareRequest->assigned_to;
        if ($assigneeId) {
            $assignee = User::findOrFail($assigneeId);
            abort_unless($assignee->isRole('doctor', 'nurse'), 422, 'Triage can only be assigned to clinical staff.');
        }

        $urgentCareRequest->update([
            'status' => $data['status'],
            'assigned_to' => $assigneeId,
            'assigned_at' => $assigneeId && ! $urgentCareRequest->assigned_at ? now() : $urgentCareRequest->assigned_at,
            'resolved_at' => $data['status'] === 'resolved' ? now() : null,
        ]);

        $urgentCareRequest->load(['patient', 'assignee']);

        $notifications->send(
            $urgentCareRequest->patient,
            'urgent_care.status_changed',
            'Urgent care '.$urgentCareRequest->status,
            "Your urgent care request is now {$urgentCareRequest->status}.",
            ['urgentCareRequestId' => $urgentCareRequest->id, 'status' => $urgentCareRequest->status]
        );

        $audit->record($request, 'urgent_care.updated', $urgentCareRequest, $data);

        return new UrgentCareRequestResource($urgentCareRequest);
    }

    private function priorityFor(string $severity): int
    {
        return match ($severity) {
            'critical' => 1,
            'high' => 2,
            'moderate' => 3,
            default => 4,
        };
    }

    private function queueNameFor(string $severity): string
    {
        return match ($severity) {
            'critical' => 'immediate-response',
            'high' => 'urgent-care',
            'moderate' => 'same-day-triage',
            default => 'standard-follow-up',
        };
    }

    private function selectAssignee(User $patient, string $severity): ?User
    {
        return User::query()
            ->whereIn('role', in_array($severity, ['high', 'critical'], true) ? ['doctor'] : ['doctor', 'nurse'])
            ->withCount(['doctorAppointments as active_queue_count' => fn ($query) => $query->whereIn('status', ['pending', 'approved'])])
            ->orderBy('active_queue_count')
            ->orderBy('id')
            ->first();
    }

    private function notifyCareTeam(UrgentCareRequest $triage, NotificationService $notifications): void
    {
        $recipientQuery = User::query()
            ->whereIn('role', ['admin', 'doctor', 'nurse']);

        $recipients = $recipientQuery
            ->limit($triage->severity === 'critical' ? 12 : 6)
            ->get();

        if ($triage->assignee) {
            $recipients->push($triage->assignee);
        }

        $recipients = $recipients->unique('id');

        foreach ($recipients as $recipient) {
            $notifications->send(
                $recipient,
                'urgent_care.requested',
                ucfirst($triage->severity).' urgent care request',
                "{$triage->patient->name} reported ".implode(', ', $triage->symptoms)." and prefers {$triage->preferred_channel}.",
                [
                    'urgentCareRequestId' => $triage->id,
                    'severity' => $triage->severity,
                    'queueName' => $triage->queue_name,
                    'preferredChannel' => $triage->preferred_channel,
                ]
            );
        }
    }
}
