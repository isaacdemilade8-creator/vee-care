<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Hospital bed projection. `status` is the operational bed state
 * (available/occupied/reserved/unavailable) while `isActive` reflects the
 * lifecycle toggle (deactivated beds are out of service).
 */
class BedResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'roomId' => $this->room_id,
            'room' => $this->whenLoaded('room', fn () => $this->room
                ? [
                    'id' => $this->room->id,
                    'name' => $this->room->name,
                    'ward' => $this->room->relationLoaded('ward') && $this->room->ward
                        ? ['id' => $this->room->ward->id, 'name' => $this->room->ward->name]
                        : null,
                ]
                : null),
            'bedNumber' => $this->bed_number,
            'status' => $this->status,
            'isActive' => (bool) $this->is_active,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
