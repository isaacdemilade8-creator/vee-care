<?php

namespace App\Http\Resources;

use App\Models\HospitalApplication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin HospitalApplication */
class HospitalApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hospitalName' => $this->hospital_name,
            'slug' => $this->slug,
            'type' => $this->type,
            'contactName' => $this->contact_name,
            'contactEmail' => $this->contact_email,
            'contactPhone' => $this->contact_phone,
            'description' => $this->description,
            'status' => $this->status,
            'reviewNotes' => $this->review_notes,
            'reviewedAt' => $this->reviewed_at?->toISOString(),
            'tenantId' => $this->tenant_id,
            'tenant' => $this->whenLoaded('tenant', fn () => new TenantResource($this->tenant)),
            'invitation' => $this->whenLoaded('latestInvitation', fn () => [
                'id' => $this->latestInvitation->id,
                'email' => $this->latestInvitation->email,
                'expiresAt' => $this->latestInvitation->expires_at?->toISOString(),
                'usedAt' => $this->latestInvitation->used_at?->toISOString(),
            ]),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
