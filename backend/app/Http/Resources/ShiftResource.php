<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shift definition projection. Times are returned as `HH:MM` hospital-local
 * wall clock; an end time earlier than the start time is a midnight-crossing
 * shift (e.g. 22:00 -> 06:00) and is not an error.
 */
class ShiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'startTime' => $this->formatTime($this->start_time),
            'endTime' => $this->formatTime($this->end_time),
            'description' => $this->description,
            'status' => $this->status,
            'dutiesCount' => $this->whenCounted('dutyAssignments'),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }

    protected function formatTime(?string $time): ?string
    {
        return $time !== null ? substr($time, 0, 5) : null;
    }
}
