<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Duty assignment projection. The practitioner, shift, department and ward are
 * inlined (lighter than UserResource to keep list responses N+1-free); the
 * shift's wall-clock window plus `dutyDate` and `status` fully describe the
 * assignment.
 */
class DutyAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'practitioner' => $this->whenLoaded('practitioner', fn () => $this->practitioner ? [
                'id' => $this->practitioner->id,
                'name' => $this->practitioner->name,
                'role' => $this->practitioner->role,
                'specialty' => $this->practitioner->specialty,
            ] : null),
            'shift' => $this->whenLoaded('shift', fn () => $this->shift ? [
                'id' => $this->shift->id,
                'name' => $this->shift->name,
                'startTime' => $this->formatTime($this->shift->start_time),
                'endTime' => $this->formatTime($this->shift->end_time),
                'status' => $this->shift->status,
            ] : null),
            'department' => $this->whenLoaded('department', fn () => $this->department ? [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ] : null),
            'ward' => $this->whenLoaded('ward', fn () => $this->ward ? [
                'id' => $this->ward->id,
                'name' => $this->ward->name,
            ] : null),
            'dutyDate' => $this->duty_date,
            'status' => $this->status,
            'notes' => $this->notes,
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }

    protected function formatTime(?string $time): ?string
    {
        return $time !== null ? substr($time, 0, 5) : null;
    }
}
