<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PharmacyRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'clinicalNote' => $this->clinical_note,
            'status' => $this->status,
            'patient' => new UserResource($this->whenLoaded('patient')),
            'doctor' => new UserResource($this->whenLoaded('doctor')),
            'reviewedBy' => new UserResource($this->whenLoaded('reviewedBy')),
            'reviewedAt' => $this->reviewed_at?->toISOString(),
            'items' => PharmacyRequestItemResource::collection($this->whenLoaded('items')),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
