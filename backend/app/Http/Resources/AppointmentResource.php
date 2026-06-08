<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'scheduledAt' => $this->scheduled_at?->toISOString(),
            'reason' => $this->reason,
            'notes' => $this->notes,
            'status' => $this->status,
            'patient' => new UserResource($this->whenLoaded('patient')),
            'doctor' => new UserResource($this->whenLoaded('doctor')),
            'prescription' => new PrescriptionResource($this->whenLoaded('prescription')),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
