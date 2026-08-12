<?php

namespace App\Services;

use App\Enums\PlatformRole;
use App\Models\PlatformUser;
use App\Models\PlatformUserInvitation;

/**
 * Authoritative platform-user management rules.
 *
 * The platform roles are:
 *  - platform_super_admin: full platform-user management authority.
 *  - platform_admin: limited — may view the directory, invite other admins and
 *    manage other admins, but can never touch a super-admin or escalate a
 *    role.
 *
 * These rules are enforced server-side on every mutating endpoint; the
 * frontend only reflects what the backend permits. They also preserve the
 * control-plane invariants: no self-demotion, no self-deactivation, and the
 * system never ends up with zero usable (active) super-admins.
 */
class PlatformUserAccessService
{
    /**
     * A platform admin may only invite users to the platform_admin role.
     */
    public function assertCanInvite(PlatformUser $actor, string $role): void
    {
        if ($this->isSuperAdmin($actor)) {
            return;
        }

        if ($role !== PlatformRole::Admin->value) {
            abort(403, 'Only a platform super admin can invite super admins.');
        }
    }

    /**
     * A platform admin may only manage other platform admins; super-admins may
     * manage any platform user.
     */
    public function assertCanManage(PlatformUser $actor, PlatformUser $target): void
    {
        if ($this->isSuperAdmin($actor)) {
            return;
        }

        if (! $target->isRole(PlatformRole::Admin->value)) {
            abort(403, 'You can only manage other platform admins.');
        }
    }

    /**
     * Validate a role change request before it is applied.
     *
     * Enforces escalation prevention, self-demotion protection and the
     * last-super-admin invariant.
     */
    public function assertCanUpdateRole(PlatformUser $actor, PlatformUser $target, string $newRole): void
    {
        if ($target->role === $newRole) {
            return;
        }

        // Escalation: only a super-admin may grant the super-admin role.
        if ($newRole === PlatformRole::SuperAdmin->value && ! $this->isSuperAdmin($actor)) {
            abort(403, 'You cannot promote a user to platform super admin.');
        }

        // No user may change their own role (prevents unsafe self-demotion).
        if ($actor->id === $target->id) {
            abort(422, 'You cannot change your own role.');
        }

        $this->assertNotLastSuperAdmin($target);
    }

    /**
     * Revoking a user's own access is never allowed.
     */
    public function assertCanDeactivate(PlatformUser $actor, PlatformUser $target): void
    {
        if ($actor->id === $target->id) {
            abort(422, 'You cannot deactivate your own account.');
        }

        $this->assertCanManage($actor, $target);
        $this->assertNotLastSuperAdmin($target);
    }

    public function assertCanActivate(PlatformUser $actor, PlatformUser $target): void
    {
        $this->assertCanManage($actor, $target);
    }

    /**
     * A platform admin may only revoke invitations to the platform_admin role.
     */
    public function assertCanRevokeInvitation(PlatformUser $actor, PlatformUserInvitation $invitation): void
    {
        if ($this->isSuperAdmin($actor)) {
            return;
        }

        if ($invitation->role !== PlatformRole::Admin->value) {
            abort(403, 'You cannot revoke a super-admin invitation.');
        }
    }

    /**
     * Block demoting or deactivating the last active super-admin.
     */
    protected function assertNotLastSuperAdmin(PlatformUser $target): void
    {
        if (! $target->is_active || ! $target->isRole(PlatformRole::SuperAdmin->value)) {
            return;
        }

        $others = PlatformUser::query()
            ->where('role', PlatformRole::SuperAdmin->value)
            ->where('is_active', true)
            ->whereKeyNot($target->getKey())
            ->count();

        if ($others === 0) {
            abort(422, 'The last active platform super admin cannot be removed.');
        }
    }

    protected function isSuperAdmin(PlatformUser $user): bool
    {
        return $user->isRole(PlatformRole::SuperAdmin->value);
    }
}
