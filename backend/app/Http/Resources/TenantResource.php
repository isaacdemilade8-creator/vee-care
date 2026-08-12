<?php

namespace App\Http\Resources;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Tenant */
class TenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'type' => $this->type,
            'plan' => $this->plan,
            'status' => $this->status,
            'currency' => $this->currency,
            'database_name' => $this->database_name,
            // Connection details (host/port/username) and credentials are
            // environment-level infrastructure, never per-tenant configuration;
            // they are intentionally not exposed to the platform UI.
            'settings' => $this->settings,
            'domains' => TenantDomainResource::collection($this->whenLoaded('domains')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
