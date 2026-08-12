<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlatformAuditLogResource;
use App\Models\PlatformAuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only control-plane audit trail.
 *
 * Platform audit records are written throughout the hospital-onboarding
 * lifecycle (HospitalApplicationController::audit). This index is the only
 * consumer of those records and is restricted to platform administrators.
 */
class PlatformAuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $logs = PlatformAuditLog::query()
            ->with('actor', 'application', 'tenant')
            ->when($request->string('event')->toString(), function ($query, string $event): void {
                $query->where('event', $event);
            })
            ->latest()
            ->paginate($request->integer('per_page', 25));

        return PlatformAuditLogResource::collection($logs);
    }
}
