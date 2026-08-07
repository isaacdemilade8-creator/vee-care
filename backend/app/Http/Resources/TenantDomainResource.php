<?php

namespace App\Http\Resources;

use App\Models\TenantDomain;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TenantDomain */
class TenantDomainResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'domain' => $this->domain,
            'is_primary' => $this->is_primary,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
