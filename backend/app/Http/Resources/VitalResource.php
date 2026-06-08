<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VitalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'temperature' => $this->temperature,
            'heartRate' => $this->heart_rate,
            'bloodPressure' => $this->blood_pressure,
            'weight' => $this->weight,
            'height' => $this->height,
            'patient' => new UserResource($this->whenLoaded('patient')),
            'recordedBy' => new UserResource($this->whenLoaded('recordedBy')),
            'recordedAt' => $this->recorded_at?->toISOString(),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
