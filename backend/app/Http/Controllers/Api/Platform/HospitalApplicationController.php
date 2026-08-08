<?php

namespace App\Http\Controllers\Api\Platform;

use App\Enums\HospitalApplicationStatus;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\HospitalApplicationResource;
use App\Http\Resources\UserResource;
use App\Mail\HospitalAdminInvitation as HospitalAdminInvitationMail;
use App\Models\Branch;
use App\Models\HospitalAdminInvitation;
use App\Models\HospitalApplication;
use App\Models\Organization;
use App\Models\PlatformAuditLog;
use App\Models\PlatformUser;
use App\Models\Tenant;
use App\Models\User;
use App\Rules\AvailableHospitalSubdomain;
use App\Services\TenantDatabaseManager;
use App\Services\TenantProvisioner;
use App\Services\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HospitalApplicationController extends Controller
{
    public function __construct(
        private readonly TenantProvisioner $provisioner,
        private readonly TenantDatabaseManager $databases,
        private readonly TenantResolver $resolver,
    ) {
    }

    /**
     * Public hospital application submission.
     *
     * Creates a `pending` application on the control plane. It never creates a
     * tenant or a database, never accepts roles, and never accepts database
     * credentials. The endpoint is rate limited to prevent abuse.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hospital_name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:63', new AvailableHospitalSubdomain],
            'type' => ['sometimes', 'in:clinic,hospital,lab,pharmacy'],
            'contact_name' => ['required', 'string', 'max:255'],
            'contact_email' => ['required', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $application = HospitalApplication::query()->create([
            'hospital_name' => $data['hospital_name'],
            'slug' => strtolower(trim($data['slug'])),
            'type' => $data['type'] ?? 'hospital',
            'contact_name' => $data['contact_name'],
            'contact_email' => $data['contact_email'],
            'contact_phone' => $data['contact_phone'] ?? null,
            'description' => $data['description'] ?? null,
            'status' => HospitalApplicationStatus::Pending->value,
        ]);

        $this->audit('hospital_application.submitted', null, $request, [
            'application_id' => $application->id,
            'metadata' => ['slug' => $application->slug],
        ]);

        return response()->json([
            'data' => new HospitalApplicationResource($application),
        ], 201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $applications = HospitalApplication::query()
            ->with('tenant')
            ->when($request->string('status')->toString(), fn ($query, string $status) => $query->where('status', $status))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return HospitalApplicationResource::collection($applications);
    }

    public function show(HospitalApplication $application): HospitalApplicationResource
    {
        return new HospitalApplicationResource($application->load('tenant', 'latestInvitation'));
    }

    /**
     * Mark an application as under review.
     */
    public function review(Request $request, HospitalApplication $application): HospitalApplicationResource
    {
        abort_if($application->isTerminal(), 422, 'This application has already been processed.');

        $application->update([
            'status' => HospitalApplicationStatus::UnderReview->value,
            'reviewer_id' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        $this->audit('hospital_application.reviewed', $request->user(), $request, [
            'application_id' => $application->id,
        ]);

        return new HospitalApplicationResource($application);
    }

    /**
     * Approve an application and provision its tenant.
     *
     * The hospital administrator's password is never generated or returned
     * here: the tenant is provisioned with a throwaway password and a single
     * use, expiring invitation is issued instead. Only the invitation token
     * digest is stored; the plaintext token is returned to the approver once.
     */
    public function approve(Request $request, HospitalApplication $application): HospitalApplicationResource
    {
        abort_if($application->isTerminal(), 422, 'This application has already been processed.');

        if ($application->tenant_id) {
            abort(422, 'This application has already been provisioned.');
        }

        $this->assertSlugAvailable($application->slug);

        $tenant = null;
        $token = Str::random(64);
        $invitation = null;

        try {
            $tenant = $this->provisioner->provision($application->hospital_name, [
                'slug' => $application->slug,
                'email' => $application->contact_email,
                'password' => Str::random(16),
                'type' => $application->type ?: 'hospital',
                'plan' => 'starter',
                'settings' => ['locale' => 'en', 'timezone' => 'UTC'],
            ]);

            $invitation = $this->issueInvitation($application, $tenant, $token);
        } catch (\Throwable $e) {
            $this->rollbackFailedProvision($tenant, $application);

            throw ValidationException::withMessages([
                'application' => ['Approval failed: '.$e->getMessage()],
            ]);
        }

        $this->dispatchInvitationEmail($invitation, $token);

        $application->update([
            'status' => HospitalApplicationStatus::Approved->value,
            'tenant_id' => $tenant->id,
            'reviewer_id' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        $this->audit('hospital_application.approved', $request->user(), $request, [
            'application_id' => $application->id,
            'tenant_id' => $tenant->id,
            'metadata' => ['email' => $application->contact_email],
        ]);

        $resource = new HospitalApplicationResource($application->load('tenant', 'latestInvitation'));

        return $resource->additional([
            'invitation' => [
                'token' => $token,
                'email' => $invitation->email,
                'expiresAt' => $invitation->expires_at->toISOString(),
                'acceptUrl' => url('/api/platform/hospital-applications/invitations/'.$token.'/accept'),
            ],
        ]);
    }

    public function reject(Request $request, HospitalApplication $application): HospitalApplicationResource
    {
        abort_if($application->isTerminal(), 422, 'This application has already been processed.');

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        // Free the subdomain for a future applicant by retiring the original slug.
        $application->update([
            'slug' => $application->slug.'-rejected-'.$application->id,
            'status' => HospitalApplicationStatus::Rejected->value,
            'reviewer_id' => $request->user()->id,
            'reviewed_at' => now(),
            'review_notes' => $data['reason'] ?? null,
        ]);

        $this->audit('hospital_application.rejected', $request->user(), $request, [
            'application_id' => $application->id,
            'metadata' => ['reason' => $data['reason'] ?? null],
        ]);

        return new HospitalApplicationResource($application);
    }

    /**
     * Redeem a hospital-administrator invitation.
     *
     * Public and rate limited. Validates the single-use, expiring token, then
     * sets the hospital administrator's password inside the tenant database.
     * Never returns the token or a password.
     */
    public function acceptInvitation(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $invitation = HospitalAdminInvitation::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if (! $invitation) {
            throw ValidationException::withMessages(['token' => ['This invitation is invalid.']]);
        }

        if (! $invitation->isRedeemable()) {
            $reason = $invitation->isExpired()
                ? 'This invitation has expired.'
                : 'This invitation has already been used.';

            throw ValidationException::withMessages(['token' => [$reason]]);
        }

        $tenant = $invitation->tenant;

        $this->resolver->setCurrent($tenant);

        try {
            $user = User::query()->where('email', $invitation->email)->first();

            if (! $user) {
                $user = User::query()->create([
                    'organization_id' => Organization::query()->value('id'),
                    'branch_id' => Branch::query()->value('id'),
                    'name' => $data['name'] ?? 'Hospital Administrator',
                    'email' => $invitation->email,
                    'password' => $data['password'],
                    'role' => Role::HospitalAdmin->value,
                ]);
            } else {
                $user->update([
                    'name' => $data['name'] ?? $user->name,
                    'password' => $data['password'],
                ]);
            }

            $invitation->update(['used_at' => now()]);

            $this->audit('hospital_application.invitation_accepted', null, $request, [
                'application_id' => $invitation->application_id,
                'tenant_id' => $tenant->id,
                'metadata' => ['email' => $invitation->email],
            ]);

            return response()->json([
                'message' => 'Invitation accepted. You can now log in.',
                'user' => new UserResource($user),
            ]);
        } finally {
            $this->resolver->clear();
        }
    }

    /**
     * Create a single-use, expiring invitation and persist only its digest.
     */
    protected function issueInvitation(HospitalApplication $application, Tenant $tenant, string $token): HospitalAdminInvitation
    {
        return HospitalAdminInvitation::query()->create([
            'application_id' => $application->id,
            'tenant_id' => $tenant->id,
            'email' => $application->contact_email,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays((int) config('tenancy.invitation.expiry_days', 7)),
        ]);
    }

    /**
     * Deliver the invitation to the applicant's contact email.
     *
     * Best-effort: a mail failure is logged but never fails approval or
     * rollback of a provisioned tenant, and it never exposes the token to
     * platform administrators (the raw token is only embedded in the URL).
     */
    protected function dispatchInvitationEmail(HospitalAdminInvitation $invitation, string $token): void
    {
        try {
            Mail::to($invitation->email)->queue(
                new HospitalAdminInvitationMail(
                    $invitation,
                    url('/api/platform/hospital-applications/invitations/'.$token.'/accept'),
                ),
            );
        } catch (\Throwable $e) {
            Log::warning('Hospital admin invitation email could not be dispatched.', [
                'application_id' => $invitation->application_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Undo a partially provisioned tenant so an approval failure never leaves
     * a half-created tenant (or an occupied subdomain) behind.
     */
    protected function rollbackFailedProvision(?Tenant $tenant, HospitalApplication $application): void
    {
        if ($tenant) {
            $this->databases->dropDatabase($tenant);
            $tenant->delete();

            return;
        }

        Tenant::query()
            ->where('slug', $application->slug)
            ->where('status', TenantStatus::Failed->value)
            ->delete();
    }

    protected function assertSlugAvailable(string $slug): void
    {
        if (Tenant::query()->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages([
                'slug' => ['This subdomain is already in use.'],
            ]);
        }
    }

    /**
     * Record a control-plane audit event. Actors may be null for public
     * (unauthenticated) events such as submissions and invitation redemptions.
     * Never records passwords, tokens or database credentials.
     */
    protected function audit(string $event, ?PlatformUser $actor, Request $request, array $context = []): PlatformAuditLog
    {
        return PlatformAuditLog::query()->create([
            'event' => $event,
            'platform_user_id' => $actor?->id,
            'application_id' => $context['application_id'] ?? null,
            'tenant_id' => $context['tenant_id'] ?? null,
            'metadata' => $context['metadata'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
