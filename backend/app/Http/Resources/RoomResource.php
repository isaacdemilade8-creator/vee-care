<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Hospital room projection with its parent ward when loaded.
 */
class RoomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'wardId' => $this->ward_id,
            'ward' => $this->whenLoaded('ward', fn () => $this->ward
                ? ['id' => $this->ward->id, 'name' => $this->ward->name]
                : null),
            'name' => $this->name,
            'capacity' => $this->capacity,
            'status' => $this->status,
            'isActive' => $this->status === 'active',
            'bedsCount' => $this->whenCounted('beds'),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
