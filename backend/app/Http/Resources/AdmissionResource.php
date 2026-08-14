<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Inpatient admission projection. Includes the patient, the optional attending
 * practitioner and the resolved structure chain so the admin UI can render an
 * admission without extra requests. Status is exactly `admitted` or
 * `discharged`; a discharged admission keeps its bed/room/ward references so
 * history always shows where the patient stayed.
 */
class AdmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'patient' => new UserResource($this->whenLoaded('patient')),
            'practitioner' => $this->whenLoaded('practitioner', fn () => $this->practitioner
                ? new UserResource($this->practitioner)
                : null),
            'department' => $this->whenLoaded('department', fn () => $this->department
                ? ['id' => $this->department->id, 'name' => $this->department->name]
                : null),
            'ward' => $this->whenLoaded('ward', fn () => $this->ward
                ? ['id' => $this->ward->id, 'name' => $this->ward->name]
                : null),
            'room' => $this->whenLoaded('room', fn () => $this->room
                ? ['id' => $this->room->id, 'name' => $this->room->name]
                : null),
            'bed' => $this->whenLoaded('bed', fn () => $this->bed
                ? new BedResource($this->bed)
                : null),
            'status' => $this->status,
            'admittedAt' => $this->admitted_at?->toISOString(),
            'dischargedAt' => $this->discharged_at?->toISOString(),
            'reason' => $this->reason,
            'notes' => $this->notes,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
