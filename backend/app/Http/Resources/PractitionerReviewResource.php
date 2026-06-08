<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PractitionerReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rating' => $this->rating,
            'comment' => $this->comment,
            'patient' => new UserResource($this->whenLoaded('patient')),
            'practitioner' => new UserResource($this->whenLoaded('practitioner')),
            'appointmentId' => $this->appointment_id,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
