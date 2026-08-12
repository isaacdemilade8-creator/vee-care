<?php

namespace App\Http\Controllers\Api\Platform;

use App\Enums\PlatformRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\PendingPlatformUserInvitationResource;
use App\Http\Resources\PlatformUserResource;
use App\Mail\PlatformUserInvitation as PlatformUserInvitationMail;
use App\Models\PlatformAuditLog;
use App\Models\PlatformUser;
use App\Models\PlatformUserInvitation;
use App\Services\PlatformUserAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Platform-user management (control plane).
 *
 * Every endpoint is restricted to platform roles and enforces the platform
 * authorization model through PlatformUserAccessService: super-admins manage
 * everyone; admins only manage other admins, may not escalate roles, and may
 * never deactivate or demote themselves or the last active super-admin.
 *
 * User creation runs exclusively through the single-use invitation flow; no
 * plaintext password is ever generated, stored or returned.
 */
class PlatformUserController extends Controller
{
    public function __construct(private readonly PlatformUserAccessService $access)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $users = PlatformUser::query()
            ->when($request->string('search')->toString(), function ($query, string $search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($request->string('role')->toString(), fn ($query, string $role) => $query->where('role', $role))
            ->when($request->string('status')->toString(), function ($query, string $status): void {
                if ($status === 'active') {
                    $query->where('is_active', true);
                } elseif ($status === 'inactive') {
                    $query->where('is_active', false);
                }
            })
            ->latest()
            ->paginate($request->integer('per_page', 15));

        $pending = PlatformUserInvitation::query()
            ->with('inviter')
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return PlatformUserResource::collection($users)->additional([
            'pending' => PendingPlatformUserInvitationResource::collection($pending),
        ]);
    }

    public function show(PlatformUser $user): PlatformUserResource
    {
        return new PlatformUserResource($user);
    }

    /**
     * Invite a platform user via a single-use, expiring token.
     *
     * No user row is created here and no password is generated: the token
     * digest is stored, the plaintext token is returned to the inviter once,
     * and the invitee's account is created on acceptance.
     */
    public function invite(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(PlatformRole::values())],
        ]);

        $this->access->assertCanInvite($request->user(), $data['role']);

        $this->assertEmailAvailable($data['email']);

        $token = Str::random(64);

        $invitation = PlatformUserInvitation::query()->create([
            'inviter_id' => $request->user()->id,
            'email' => strtolower($data['email']),
            'role' => $data['role'],
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays((int) config('tenancy.invitation.expiry_days', 7)),
        ]);

        $this->dispatchInvitationEmail($invitation, $token);

        $this->audit($request, 'platform_user.invited', $request->user(), [
            'metadata' => [
                'email' => $invitation->email,
                'role' => $invitation->role,
                'invitation_id' => $invitation->id,
            ],
        ]);

        return response()->json([
            'invitation' => [
                'token' => $token,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'expiresAt' => $invitation->expires_at->toISOString(),
                'acceptUrl' => url('/api/platform/users/invitations/'.$token.'/accept'),
            ],
        ], 201);
    }

    /**
     * Update name, email or role of a platform user.
     */
    public function update(Request $request, PlatformUser $user): PlatformUserResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['sometimes', Rule::in(PlatformRole::values())],
        ]);

        $this->access->assertCanManage($request->user(), $user);

        if (isset($data['role'])) {
            $this->access->assertCanUpdateRole($request->user(), $user, $data['role']);
        }

        $previousRole = $user->role;
        $user->update($data);

        if (isset($data['role']) && $data['role'] !== $previousRole) {
            $this->audit($request, 'platform_user.role_changed', $request->user(), [
                'subject_user_id' => $user->id,
                'metadata' => [
                    'email' => $user->email,
                    'from' => $previousRole,
                    'to' => $user->role,
                ],
            ]);
        } else {
            $this->audit($request, 'platform_user.updated', $request->user(), [
                'subject_user_id' => $user->id,
                'metadata' => [
                    'email' => $user->email,
                    'changed' => array_keys($data),
                ],
            ]);
        }

        return new PlatformUserResource($user->refresh());
    }

    public function activate(Request $request, PlatformUser $user): PlatformUserResource
    {
        $this->access->assertCanActivate($request->user(), $user);

        if (! $user->is_active) {
            $user->update(['is_active' => true]);

            $this->audit($request, 'platform_user.activated', $request->user(), [
                'subject_user_id' => $user->id,
                'metadata' => ['email' => $user->email],
            ]);
        }

        return new PlatformUserResource($user->refresh());
    }

    /**
     * Deactivate a platform user and revoke their sessions immediately.
     */
    public function deactivate(Request $request, PlatformUser $user): PlatformUserResource
    {
        $this->access->assertCanDeactivate($request->user(), $user);

        $user->update(['is_active' => false]);
        $user->tokens()->delete();

        $this->audit($request, 'platform_user.deactivated', $request->user(), [
            'subject_user_id' => $user->id,
            'metadata' => ['email' => $user->email],
        ]);

        return new PlatformUserResource($user->refresh());
    }

    /**
     * Revoke a pending invitation before it is redeemed.
     */
    public function revokeInvitation(Request $request, PlatformUserInvitation $invitation): JsonResponse
    {
        abort_if($invitation->isAccepted(), 422, 'This invitation has already been used.');
        abort_if($invitation->isExpired(), 422, 'This invitation has expired.');

        $this->access->assertCanRevokeInvitation($request->user(), $invitation);

        $email = $invitation->email;
        $role = $invitation->role;
        $invitation->delete();

        $this->audit($request, 'platform_user.invitation_revoked', $request->user(), [
            'metadata' => [
                'email' => $email,
                'role' => $role,
                'invitation_id' => $invitation->id,
            ],
        ]);

        return response()->json(['message' => 'Invitation revoked.']);
    }

    /**
     * Redeem a platform-user invitation.
     *
     * Public and rate limited. Validates the single-use, expiring token, then
     * creates the platform user (control plane) with the invited role. Never
     * returns the token or a password.
     */
    public function acceptInvitation(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        [$user] = DB::transaction(function () use ($token, $data): array {
            $invitation = PlatformUserInvitation::query()
                ->where('token_hash', hash('sha256', $token))
                ->first();

            if (! $invitation || ! $invitation->isRedeemable()) {
                throw ValidationException::withMessages([
                    'token' => ['This invitation is invalid or has expired.'],
                ]);
            }

            // The invitee must not already be a platform user (e.g. a second
            // token for an email that was redeemed through a later invitation).
            if (PlatformUser::query()->where('email', $invitation->email)->exists()) {
                throw ValidationException::withMessages([
                    'token' => ['This invitation is invalid or has expired.'],
                ]);
            }

            $user = PlatformUser::query()->create([
                'name' => $data['name'] ?? 'Platform Administrator',
                'email' => $invitation->email,
                'password' => $data['password'],
                'role' => $invitation->role,
                'is_active' => true,
            ]);

            $invitation->update(['accepted_at' => now()]);

            return [$user, $invitation];
        });

        $this->audit($request, 'platform_user.invitation_accepted', null, [
            'subject_user_id' => $user->id,
            'metadata' => [
                'email' => $user->email,
                'role' => $user->role,
            ],
        ]);

        return response()->json([
            'message' => 'Invitation accepted. You can now sign in to the platform.',
            'user' => new PlatformUserResource($user),
        ]);
    }

    protected function assertEmailAvailable(string $email): void
    {
        $email = strtolower($email);

        $existsAsUser = PlatformUser::query()->where('email', $email)->exists();
        $existsAsPending = PlatformUserInvitation::query()
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->exists();

        if ($existsAsUser || $existsAsPending) {
            throw ValidationException::withMessages([
                'email' => ['A platform user with this email already exists.'],
            ]);
        }
    }

    /**
     * Best-effort invitation delivery; a mail failure is logged but never
     * fails the invite, and the raw token is only embedded in the URL.
     */
    protected function dispatchInvitationEmail(PlatformUserInvitation $invitation, string $token): void
    {
        try {
            Mail::to($invitation->email)->queue(
                new PlatformUserInvitationMail(
                    $invitation,
                    url('/api/platform/users/invitations/'.$token.'/accept'),
                ),
            );
        } catch (\Throwable $e) {
            Log::warning('Platform user invitation email could not be dispatched.', [
                'invitation_id' => $invitation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Record a control-plane audit event. Never records passwords or tokens.
     *
     * @param  array<string, mixed>  $context
     */
    protected function audit(Request $request, string $event, ?PlatformUser $actor, array $context = []): PlatformAuditLog
    {
        return PlatformAuditLog::query()->create([
            'event' => $event,
            'platform_user_id' => $actor?->id,
            'subject_user_id' => $context['subject_user_id'] ?? null,
            'metadata' => $context['metadata'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
