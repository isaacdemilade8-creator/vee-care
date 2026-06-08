<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UrgentCareRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'severity' => $this->severity,
            'priority' => $this->priority,
            'preferredChannel' => $this->preferred_channel,
            'queueName' => $this->queue_name,
            'status' => $this->status,
            'symptoms' => $this->symptoms,
            'message' => $this->message,
            'patient' => new UserResource($this->whenLoaded('patient')),
            'assignee' => new UserResource($this->whenLoaded('assignee')),
            'assignedAt' => $this->assigned_at?->toISOString(),
            'resolvedAt' => $this->resolved_at?->toISOString(),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
