<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PatientProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'patientNumber' => $this->patient_number,
            'allergies' => $this->allergies ?? [],
            'chronicConditions' => $this->chronic_conditions ?? [],
            'emergencyContact' => $this->emergency_contact,
            'user' => new UserResource($this->whenLoaded('user')),
        ];
    }
}
