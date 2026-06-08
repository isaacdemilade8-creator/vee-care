<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrescriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'medication' => $this->medication,
            'dosage' => $this->dosage,
            'instructions' => $this->instructions,
            'issuedAt' => $this->issued_at?->toISOString(),
            'patient' => new UserResource($this->whenLoaded('patient')),
            'doctor' => new UserResource($this->whenLoaded('doctor')),
        ];
    }
}
