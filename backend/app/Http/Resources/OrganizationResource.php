<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationResource extends JsonResource
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
            'settings' => $this->settings,
            'usersCount' => $this->whenCounted('users'),
            'branchesCount' => $this->whenCounted('branches'),
            'branches' => BranchResource::collection($this->whenLoaded('branches')),
        ];
    }
}
