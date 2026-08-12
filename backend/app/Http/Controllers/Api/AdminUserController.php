<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminUserResource;
use App\Http\Resources\PendingUserInvitationResource;
use App\Mail\TenantUserInvitation;
use App\Models\Organization;
use App\Models\PatientProfile;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\AuditService;
use App\Services\TenantConfigurationService;
use App\Services\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Tenant (hospital) user & staff management.
 *
 * Every endpoint runs inside the tenant database (ResolveTenant switches the
 * connection before these routes execute), so a route-model-bound User/UserInvitation
 * can only ever resolve to a record of the request's own hospital: another
 * tenant's user id yields a 404 and another tenant's invitation token fails
 * validation. The route group adds `role:hospital_admin`, and all lifecycle
 * rules (disabled roles, platform roles, last-admin protection, self-protection)
 * are enforced authoritatively here and in the shared middleware.
 */
class AdminUserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = User::query()->with('branch')->latest();

        $query
            ->when($request->string('search')->toString(), function ($q, string $search): void {
                $q->where(function ($nested) use ($search): void {
                    $nested->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($request->string('role')->toString(), fn ($q, string $role) => $q->where('role', $role))
            ->when($request->string('status')->toString(), function ($q, string $status): void {
                if ($status === 'active') {
                    $q->where('is_active', true);
                } elseif ($status === 'inactive') {
                    $q->where('is_active', false);
                }
            });

        $pending = UserInvitation::query()
            ->with('inviter')
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return AdminUserResource::collection($query->paginate($request->integer('per_page', 10)))
            ->additional(['pending' => PendingUserInvitationResource::collection($pending)]);
    }

    public function show(User $user): AdminUserResource
    {
        return new AdminUserResource($user->load('branch'));
    }

    public function storeUser(Request $request, AuditService $audit, TenantResolver $resolver, TenantConfigurationService $configuration): AdminUserResource
    {
        $allowedRoles = Role::assignableByAdmin();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in($allowedRoles)],
            'specialty' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
        ]);

        $this->assertRoleEnabled($data['role'], $resolver, $configuration);

        $user = User::create([
            ...$data,
            'organization_id' => $request->user()->organization_id,
            'password' => Hash::make($data['password']),
            'is_active' => true,
        ]);

        PatientProfile::ensureFor($user);

        $audit->record($request, 'admin.user_created', $user, ['email' => $user->email, 'role' => $user->role]);

        return new AdminUserResource($user->load('branch'));
    }

    public function updateUser(Request $request, User $user, AuditService $audit, TenantResolver $resolver, TenantConfigurationService $configuration): AdminUserResource
    {
        // An administrator may keep their own admin role, but no user may be
        // promoted to or reassigned as a hospital administrator from here —
        // matches the create path (Role::assignableByAdmin()).
        $allowedRoles = [
            ...Role::assignableByAdmin(),
            ...($user->isRole(Role::HospitalAdmin) ? [Role::HospitalAdmin->value] : []),
        ];

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'role' => ['sometimes', Rule::in($allowedRoles)],
            'specialty' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
        ]);

        if ($user->isRole(Role::HospitalAdmin) && $user->id !== $request->user()->id) {
            abort(403, 'Hospital admin accounts cannot be reassigned.');
        }

        if (isset($data['role'])) {
            $this->assertRoleEnabled($data['role'], $resolver, $configuration);

            // Never let the last hospital administrator demote their own account.
            if (
                $user->id === $request->user()->id
                && $user->isRole(Role::HospitalAdmin)
                && $data['role'] !== Role::HospitalAdmin->value
                && $this->activeAdminCount() === 1
            ) {
                throw ValidationException::withMessages([
                    'role' => ['You are the only hospital administrator and cannot be demoted.'],
                ]);
            }
        }

        $previousRole = $user->role;
        $previousBranch = $user->branch_id;

        $user->update($data);

        if (isset($data['role']) && $data['role'] !== $previousRole) {
            $audit->record($request, 'admin.user_role_changed', $user, [
                'email' => $user->email,
                'from' => $previousRole,
                'to' => $user->role,
            ]);
        }

        if (array_key_exists('branch_id', $data) && $data['branch_id'] !== $previousBranch) {
            $audit->record($request, 'admin.branch_assignment_changed', $user, [
                'email' => $user->email,
                'branch_id' => $user->branch_id,
            ]);
        }

        if (! isset($data['role']) && ! array_key_exists('branch_id', $data)) {
            $audit->record($request, 'admin.user_updated', $user, ['changed' => array_keys($data)]);
        }

        return new AdminUserResource($user->fresh()->load('branch'));
    }

    public function destroyUser(Request $request, User $user, AuditService $audit): JsonResponse
    {
        abort_if($request->user()->id === $user->id, 422, 'You cannot delete your own account.');

        abort_if($user->isRole(Role::HospitalAdmin), 403, 'Hospital admin accounts cannot be deleted.');

        $audit->record($request, 'admin.user_deleted', $user, ['name' => $user->name]);

        $user->delete();

        return response()->json(['message' => 'User deleted.']);
    }

    public function activate(Request $request, User $user, AuditService $audit): AdminUserResource
    {
        if (! $user->is_active) {
            $user->update(['is_active' => true]);

            $audit->record($request, 'admin.user_activated', $user, ['email' => $user->email]);
        }

        return new AdminUserResource($user->refresh()->load('branch'));
    }

    public function deactivate(Request $request, User $user, AuditService $audit): AdminUserResource
    {
        abort_if($request->user()->id === $user->id, 422, 'You cannot deactivate your own account.');

        if ($user->isRole(Role::HospitalAdmin) && $this->activeAdminCount() === 1) {
            abort(422, 'You cannot deactivate the last hospital administrator.');
        }

        if ($user->is_active) {
            $user->update(['is_active' => false]);
            $user->tokens()->delete();

            $audit->record($request, 'admin.user_deactivated', $user, ['email' => $user->email]);
        }

        return new AdminUserResource($user->refresh()->load('branch'));
    }

    /**
     * Invite a tenant user via a single-use, expiring token.
     *
     * No user row is created here and no password is generated: the token
     * digest is stored, the plaintext token is returned to the inviter once,
     * and the invitee's account is created on acceptance. Role availability is
     * enforced at invite time and re-checked at acceptance time.
     */
    public function invite(Request $request, AuditService $audit, TenantResolver $resolver, TenantConfigurationService $configuration): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(Role::assignableByAdmin())],
        ]);

        $this->assertRoleEnabled($data['role'], $resolver, $configuration);
        $this->assertEmailAvailable(strtolower($data['email']));

        $token = Str::random(64);

        $invitation = UserInvitation::query()->create([
            'inviter_id' => $request->user()->id,
            'email' => strtolower($data['email']),
            'name' => $data['name'] ?? null,
            'role' => $data['role'],
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays((int) config('tenancy.invitation.expiry_days', 7)),
        ]);

        $this->dispatchInvitationEmail($invitation, $token, $resolver->current()?->name ?? 'your hospital');

        $audit->record($request, 'admin.user_invited', $invitation, [
            'email' => $invitation->email,
            'role' => $invitation->role,
            'invitation_id' => $invitation->id,
        ]);

        return response()->json([
            'invitation' => [
                'token' => $token,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'name' => $invitation->name,
                'expiresAt' => $invitation->expires_at?->toISOString(),
                'acceptUrl' => $this->acceptUrl($request, $token),
            ],
        ], 201);
    }

    /**
     * Redeem a hospital user invitation. Public and rate limited.
     *
     * Validates the single-use, expiring token, then creates the user in the
     * tenant database with the invited role and the password the invitee sets.
     * Never returns the token or a password.
     */
    public function acceptInvitation(Request $request, string $token, AuditService $audit, TenantResolver $resolver, TenantConfigurationService $configuration): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        [$user] = DB::transaction(function () use ($token, $data, $resolver, $configuration): array {
            $invitation = UserInvitation::query()
                ->where('token_hash', hash('sha256', $token))
                ->first();

            if (! $invitation || ! $invitation->isRedeemable()) {
                throw ValidationException::withMessages([
                    'token' => ['This invitation is invalid or has expired.'],
                ]);
            }

            // A role that has since been disabled at this hospital cannot be
            // granted even through a previously issued invitation.
            if (! $this->roleEnabled($invitation->role, $resolver, $configuration)) {
                throw ValidationException::withMessages([
                    'token' => ['This invitation is invalid or has expired.'],
                ]);
            }

            // The invitee must not already be a tenant user (e.g. a second
            // token for an email that was redeemed through a later invitation).
            if (User::query()->where('email', $invitation->email)->exists()) {
                throw ValidationException::withMessages([
                    'token' => ['This invitation is invalid or has expired.'],
                ]);
            }

            $organizationId = User::query()->where('role', Role::HospitalAdmin->value)->value('organization_id');

            $user = User::query()->create([
                'name' => $data['name'] ?? $invitation->name ?? 'Hospital Staff',
                'email' => $invitation->email,
                'password' => $data['password'],
                'role' => $invitation->role,
                'organization_id' => $organizationId,
                'is_active' => true,
            ]);

            PatientProfile::ensureFor($user);

            $invitation->update(['accepted_at' => now()]);

            return [$user, $invitation];
        });

        $audit->record($request, 'admin.invitation_accepted', $user, [
            'email' => $user->email,
            'role' => $user->role,
        ]);

        return response()->json([
            'message' => 'Invitation accepted. You can now sign in.',
            'user' => new AdminUserResource($user),
        ]);
    }

    public function revokeInvitation(Request $request, UserInvitation $invitation, AuditService $audit): JsonResponse
    {
        abort_if($invitation->isAccepted(), 422, 'This invitation has already been used.');
        abort_if($invitation->isRevoked(), 422, 'This invitation has already been revoked.');

        $email = $invitation->email;
        $invitation->update(['revoked_at' => now()]);

        $audit->record($request, 'admin.invitation_revoked', $invitation, [
            'email' => $email,
            'invitation_id' => $invitation->id,
        ]);

        return response()->json(['message' => 'Invitation revoked.']);
    }

    /**
     * The tenant's organization and its branches, used to associate users with
     * a branch. The tenant database holds exactly one organization.
     */
    public function organization(Request $request): JsonResponse
    {
        $organization = $request->user()->organization_id
            ? Organization::query()->find($request->user()->organization_id)
            : Organization::query()->first();

        return response()->json([
            'organization' => $organization ? ['id' => $organization->id, 'name' => $organization->name] : null,
            'branches' => $organization
                ? $organization->branches()->orderBy('name')->get(['id', 'name'])
                : collect(),
        ]);
    }

    protected function assertRoleEnabled(string $role, TenantResolver $resolver, TenantConfigurationService $configuration): void
    {
        if (! $this->roleEnabled($role, $resolver, $configuration)) {
            throw ValidationException::withMessages([
                'role' => ['This role is not enabled at your hospital.'],
            ]);
        }
    }

    protected function roleEnabled(string $role, TenantResolver $resolver, TenantConfigurationService $configuration): bool
    {
        $tenant = $resolver->current();

        return $tenant === null || $configuration->isRoleEnabled($tenant, $role);
    }

    protected function activeAdminCount(): int
    {
        return User::query()->where('role', Role::HospitalAdmin->value)->where('is_active', true)->count();
    }

    protected function assertEmailAvailable(string $email): void
    {
        $existsAsUser = User::query()->where('email', $email)->exists();

        $existsAsPending = UserInvitation::query()
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();

        if ($existsAsUser || $existsAsPending) {
            throw ValidationException::withMessages([
                'email' => ['A user with this email already exists at your hospital.'],
            ]);
        }
    }

    /**
     * The frontend acceptance URL served by the tenant SPA on the same host.
     */
    protected function acceptUrl(Request $request, string $token): string
    {
        return $request->getSchemeAndHttpHost().'/invitations/'.$token.'/accept';
    }

    /**
     * Best-effort invitation delivery; a mail failure is logged but never
     * fails the invite, and the raw token is only embedded in the URL.
     */
    protected function dispatchInvitationEmail(UserInvitation $invitation, string $token, string $tenantName): void
    {
        try {
            Mail::to($invitation->email)->queue(
                new TenantUserInvitation(
                    $invitation,
                    $this->acceptUrl(request(), $token),
                    $tenantName,
                ),
            );
        } catch (\Throwable $e) {
            Log::warning('Hospital user invitation email could not be dispatched.', [
                'invitation_id' => $invitation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
