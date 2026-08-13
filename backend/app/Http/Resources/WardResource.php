<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Hospital ward projection. Includes the parent department when loaded so the
 * admin UI can render the hierarchy without extra requests.
 */
class WardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'departmentId' => $this->department_id,
            'department' => $this->whenLoaded('department', fn () => $this->department
                ? ['id' => $this->department->id, 'name' => $this->department->name]
                : null),
            'name' => $this->name,
            'type' => $this->type,
            'capacity' => $this->capacity,
            'status' => $this->status,
            'isActive' => $this->status === 'active',
            'roomsCount' => $this->whenCounted('rooms'),
            'bedsCount' => $this->whenCounted('beds'),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
