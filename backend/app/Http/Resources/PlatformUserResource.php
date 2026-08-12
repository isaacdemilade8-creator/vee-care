<?php

namespace App\Http\Resources;

use App\Models\PlatformUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe projection of a platform (control-plane) user.
 *
 * Exposes identity, platform role and access state. Deliberately excludes
 * tenant-specific fields (organization, branch, specialty, etc.) — platform
 * users never appear as tenant users. Never exposes passwords or tokens.
 *
 * @mixin PlatformUser
 */
class PlatformUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'isActive' => $this->is_active,
            'status' => $this->is_active ? 'active' : 'inactive',
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
