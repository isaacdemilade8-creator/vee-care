<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PatientCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cardNumber' => $this->card_number,
            'status' => $this->status,
            'issuedAt' => $this->issued_at?->toISOString(),
            'expiresAt' => $this->expires_at?->toDateString(),
            'metadata' => $this->metadata,
            'patient' => $this->whenLoaded('patient', fn () => [
                'id' => $this->patient->id,
                'name' => $this->patient->name,
                'role' => $this->patient->role === 'hospital_admin' ? 'admin' : $this->patient->role,
                'avatarUrl' => $this->patient->avatar_url,
            ]),
            'issuer' => $this->whenLoaded('issuer', fn () => [
                'id' => $this->issuer->id,
                'name' => $this->issuer->name,
                'role' => $this->issuer->role === 'hospital_admin' ? 'admin' : $this->issuer->role,
            ]),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
