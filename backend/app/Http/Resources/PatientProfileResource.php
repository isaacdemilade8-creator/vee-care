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
            'card' => $this->whenLoaded('user', function () {
                if ($this->user->relationLoaded('patientCard') && $this->user->patientCard) {
                    return [
                        'id' => $this->user->patientCard->id,
                        'cardNumber' => $this->user->patientCard->card_number,
                        'status' => $this->user->patientCard->status,
                    ];
                }
                return null;
            }),
        ];
    }
}
