<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lightweight tenant-user projection for the hospital-admin user management
 * surfaces. Deliberately omits social/engagement fields (followers, reviews,
 * canReview, isFollowing) that UserResource computes per row, so large staff
 * lists stay cheap and free of N+1 queries.
 */
class AdminUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'isActive' => (bool) $this->is_active,
            'status' => $this->is_active ? 'active' : 'inactive',
            'organizationId' => $this->organization_id,
            'branchId' => $this->branch_id,
            'branchName' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'specialty' => $this->specialty,
            'phone' => $this->phone,
            'avatarUrl' => $this->avatar_url,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
