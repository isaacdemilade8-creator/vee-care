<?php

namespace App\Http\Resources;

use App\Models\PlatformUserInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe projection of a pending platform-user invitation.
 *
 * Appears alongside platform users in the directory so administrators can see
 * outstanding invites. Never exposes the token digest or any secret material.
 *
 * @mixin PlatformUserInvitation
 */
class PendingPlatformUserInvitationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'role' => $this->role,
            'inviter' => $this->whenLoaded('inviter', fn () => $this->inviter ? [
                'id' => $this->inviter->id,
                'name' => $this->inviter->name,
            ] : null),
            'status' => 'invited',
            'expiresAt' => $this->expires_at?->toISOString(),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
