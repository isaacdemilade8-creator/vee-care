<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditService
{
    public function record(Request $request, string $action, ?object $subject = null, array $metadata = []): AuditLog
    {
        /** @var User|null $user */
        $user = $request->user();

        return AuditLog::create([
            'organization_id' => $user?->organization_id,
            'user_id' => $user?->id,
            'action' => $action,
            'auditable_type' => $subject ? $subject::class : null,
            'auditable_id' => $subject->id ?? null,
            'metadata' => $metadata,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
