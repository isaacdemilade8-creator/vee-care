<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Http\Resources\HospitalApplicationResource;
use App\Http\Resources\TenantResource;
use App\Models\HospitalApplication;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;

/**
 * Platform dashboard summary.
 *
 * A single, platform-authenticated request that powers the admin dashboard:
 * hospital counts by status, application counts by status, and the five most
 * recent records of each. The counts are computed server-side so the frontend
 * never issues a separate request per status.
 */
class PlatformDashboardController extends Controller
{
    public function summary(): JsonResponse
    {
        $hospitals = Tenant::query()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("COUNT(CASE WHEN status = 'active' THEN 1 END) AS active")
            ->selectRaw("COUNT(CASE WHEN status = 'suspended' THEN 1 END) AS suspended")
            ->selectRaw("COUNT(CASE WHEN status = 'provisioning' THEN 1 END) AS provisioning")
            ->selectRaw("COUNT(CASE WHEN status = 'failed' THEN 1 END) AS failed")
            ->first();

        $applications = HospitalApplication::query()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("COUNT(CASE WHEN status = 'pending' THEN 1 END) AS pending")
            ->selectRaw("COUNT(CASE WHEN status = 'under_review' THEN 1 END) AS under_review")
            ->selectRaw("COUNT(CASE WHEN status = 'approved' THEN 1 END) AS approved")
            ->selectRaw("COUNT(CASE WHEN status = 'rejected' THEN 1 END) AS rejected")
            ->first();

        return response()->json([
            'hospitals' => [
                'total' => (int) $hospitals->total,
                'active' => (int) $hospitals->active,
                'suspended' => (int) $hospitals->suspended,
                'provisioning' => (int) $hospitals->provisioning,
                'failed' => (int) $hospitals->failed,
            ],
            'applications' => [
                'total' => (int) $applications->total,
                'pending' => (int) $applications->pending,
                'underReview' => (int) $applications->under_review,
                'approved' => (int) $applications->approved,
                'rejected' => (int) $applications->rejected,
            ],
            'recentApplications' => HospitalApplicationResource::collection(
                HospitalApplication::query()->with('tenant')->latest()->limit(5)->get(),
            ),
            'recentTenants' => TenantResource::collection(
                Tenant::query()->latest()->limit(5)->get(),
            ),
        ]);
    }
}
