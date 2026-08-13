<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Hospital department projection for the hospital-admin structure surfaces.
 */
class DepartmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'isActive' => $this->status === 'active',
            'wardsCount' => $this->whenCounted('wards'),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
