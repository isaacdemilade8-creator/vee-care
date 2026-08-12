<?php

namespace App\Http\Resources;

use App\Models\PlatformAuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe, read-only projection of a control-plane audit record.
 *
 * Exposes the actor, the related application and tenant (as small inline
 * objects) and the recorded event metadata. Never exposes passwords,
 * invitation tokens or database credentials: the raw token is never persisted
 * in the first place, and metadata is written by the platform controllers
 * with no secret material.
 *
 * @mixin PlatformAuditLog
 */
class PlatformAuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'actor' => $this->whenLoaded('actor', fn () => $this->actor ? [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
                'email' => $this->actor->email,
            ] : null),
            'application' => $this->whenLoaded('application', fn () => $this->application ? [
                'id' => $this->application->id,
                'hospitalName' => $this->application->hospital_name,
                'status' => $this->application->status,
            ] : null),
            'tenant' => $this->whenLoaded('tenant', fn () => $this->tenant ? [
                'id' => $this->tenant->id,
                'name' => $this->tenant->name,
                'slug' => $this->tenant->slug,
                'status' => $this->tenant->status,
            ] : null),
            'metadata' => $this->metadata,
            'ipAddress' => $this->ip_address,
            'userAgent' => $this->user_agent,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
