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
            'database_host' => $this->database_host,
            'database_port' => $this->database_port,
            'database_username' => $this->database_username,
            'settings' => $this->settings,
            'domains' => TenantDomainResource::collection($this->whenLoaded('domains')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
