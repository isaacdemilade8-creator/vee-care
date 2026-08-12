<?php

namespace Tests\Feature;

use App\Mail\PlatformUserInvitation as PlatformUserInvitationMail;
use App\Models\PlatformAuditLog;
use App\Models\PlatformUser;
use App\Models\PlatformUserInvitation;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesTenantDatabase;
use Tests\TestCase;

/**
 * Platform-user management tests (control plane).
 *
 * Covers the platform authorization model (super-admin vs admin), the
 * control-plane invariants (no escalation, no self-demotion/deactivation,
 * last-super-admin protection), the single-use invitation lifecycle, audit
 * events, rate limiting and the control/tenant boundary.
 */
class PlatformUserManagementTest extends TestCase
{
    use CreatesTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'control']);

        $this->migrateControlDatabase();
        $this->cleanupTenantDatabases();
    }

    protected function tearDown(): void
    {
        $this->cleanupTenantDatabases();
        $this->disconnectFromTenant();

        parent::tearDown();
    }

    protected function platformUser(string $role = 'platform_admin', string $email = ''): PlatformUser
    {
        return PlatformUser::query()->create([
            'name' => Str::title(str_replace('_', ' ', $role)),
            'email' => $email ?: Str::lower($role).'.'.Str::random(8).'@vee-care.test',
            'password' => Hash::make('password123'),
            'role' => $role,
            'is_active' => true,
        ]);
    }

    protected function platformToken(string $role = 'platform_admin'): string
    {
        return $this->platformUser($role)->createToken('platform-web')->plainTextToken;
    }

    protected function invite(array $payload, ?string $token = null): TestResponse
    {
        $token ??= $this->platformToken();

        return $this->withToken($token)->postJson('http://vee-care.test/api/platform/users', $payload);
    }

    protected function inviteToken(array $payload, ?string $token = null): string
    {
        $response = $this->invite($payload, $token)->assertCreated();

        return (string) $response->json('invitation.token');
    }

    protected function accept(string $token, array $payload = []): TestResponse
    {
        return $this->postJson('http://vee-care.test/api/platform/users/invitations/'.$token.'/accept', [
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
            ...$payload,
        ]);
    }

    protected function emailFor(string $role): string
    {
        return 'invite.'.Str::lower(Str::random(8)).'.'.$role.'@vee-care.test';
    }

    protected function lastAudit(): ?PlatformAuditLog
    {
        return PlatformAuditLog::query()->orderByDesc('id')->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Access control
    |--------------------------------------------------------------------------
    */

    public function test_list_requires_platform_authentication(): void
    {
        $this->getJson('http://vee-care.test/api/platform/users')->assertUnauthorized();
    }

    public function test_list_requires_a_platform_role(): void
    {
        $this->withToken($this->platformToken('patient'))
            ->getJson('http://vee-care.test/api/platform/users')
            ->assertForbidden();
    }

    public function test_tenant_token_is_rejected_on_platform_user_endpoints(): void
    {
        $this->provisionTenant('hospital-one');

        $login = $this->postJson('http://hospital-one.vee-care.test/api/auth/login', [
            'email' => 'admin@hospital-one.vee-care.test',
            'password' => 'password123',
        ])->assertOk();

        $this->withToken($login->json('token'))
            ->getJson('http://vee-care.test/api/platform/users')
            ->assertUnauthorized();
    }

    public function test_super_admin_and_admin_can_list_users(): void
    {
        $this->platformUser('platform_super_admin');
        $this->platformUser('platform_admin');

        $this->withToken($this->platformToken('platform_super_admin'))
            ->getJson('http://vee-care.test/api/platform/users')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->withToken($this->platformToken('platform_admin'))
            ->getJson('http://vee-care.test/api/platform/users')
            ->assertOk();
    }

    public function test_list_supports_search_role_and_status_filters(): void
    {
        $activeSuper = $this->platformUser('platform_super_admin', 'ada.super@vee-care.test');
        $this->platformUser('platform_admin', 'bob.admin@vee-care.test');
        $inactiveAdmin = $this->platformUser('platform_admin', 'cara.inactive@vee-care.test');
        $inactiveAdmin->update(['is_active' => false]);

        $token = $this->platformToken('platform_super_admin');

        $this->withToken($token)
            ->getJson('http://vee-care.test/api/platform/users?role=platform_admin')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->withToken($token)
            ->getJson('http://vee-care.test/api/platform/users?search=ada.super')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $activeSuper->id);

        $this->withToken($token)
            ->getJson('http://vee-care.test/api/platform/users?status=inactive')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inactiveAdmin->id);

        $this->withToken($token)
            ->getJson('http://vee-care.test/api/platform/users?status=active')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    /*
    |--------------------------------------------------------------------------
    | Invitation lifecycle
    |--------------------------------------------------------------------------
    */

    public function test_invite_stores_only_the_token_digest_and_returns_token_once(): void
    {
        Mail::fake();

        $email = $this->emailFor('admin');
        $response = $this->invite([
            'name' => 'New Admin',
            'email' => $email,
            'role' => 'platform_admin',
        ]);

        $response->assertCreated()
            ->assertJsonPath('invitation.email', $email)
            ->assertJsonPath('invitation.role', 'platform_admin');

        $token = (string) $response->json('invitation.token');
        $this->assertSame(64, strlen($token));

        $stored = PlatformUserInvitation::query()->where('email', $email)->firstOrFail();
        $this->assertSame(hash('sha256', $token), $stored->token_hash);
        $this->assertNotSame($token, $stored->token_hash);
        $this->assertNull($stored->accepted_at);
        $this->assertTrue($stored->isRedeemable());

        // No platform user row is created until acceptance.
        $this->assertNull(PlatformUser::query()->where('email', $email)->first());

        // The token is not persisted anywhere and is never re-exposed.
        $this->assertDatabaseMissing('platform_user_invitations', ['token_hash' => $token]);

        Mail::assertQueued(PlatformUserInvitationMail::class, function (PlatformUserInvitationMail $mail) use ($email): bool {
            return $mail->hasTo($email)
                && str_contains($mail->acceptUrl, '/api/platform/users/invitations/');
        });

        $audit = $this->lastAudit();
        $this->assertSame('platform_user.invited', $audit->event);
        $this->assertSame($email, $audit->metadata['email']);
        $this->assertArrayNotHasKey('token', $audit->metadata);
    }

    public function test_super_admin_can_invite_super_admin_and_admin_can_invite_admin(): void
    {
        $this->inviteToken([
            'name' => 'Super Two',
            'email' => $this->emailFor('super'),
            'role' => 'platform_super_admin',
        ], $this->platformToken('platform_super_admin'));

        $this->inviteToken([
            'name' => 'Admin Two',
            'email' => $this->emailFor('admin'),
            'role' => 'platform_admin',
        ], $this->platformToken('platform_admin'));
    }

    public function test_admin_cannot_invite_a_super_admin(): void
    {
        $this->invite([
            'name' => 'Fake Super',
            'email' => $this->emailFor('super'),
            'role' => 'platform_super_admin',
        ], $this->platformToken('platform_admin'))
            ->assertForbidden();
    }

    public function test_invite_rejects_non_platform_roles(): void
    {
        $this->invite([
            'name' => 'Tenant Person',
            'email' => $this->emailFor('tenant'),
            'role' => 'hospital_admin',
        ])->assertUnprocessable();
    }

    public function test_duplicate_email_invite_is_rejected(): void
    {
        $email = $this->emailFor('admin');
        $this->inviteToken(['name' => 'First', 'email' => $email, 'role' => 'platform_admin']);

        $this->invite(['name' => 'Second', 'email' => $email, 'role' => 'platform_admin'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_accept_invitation_creates_active_platform_user_and_logs_in(): void
    {
        $email = $this->emailFor('admin');
        $token = $this->inviteToken(['name' => 'Invited Admin', 'email' => $email, 'role' => 'platform_admin']);

        $response = $this->accept($token, ['name' => 'Invited Admin']);

        $response->assertOk()
            ->assertJsonPath('user.email', $email)
            ->assertJsonPath('user.role', 'platform_admin')
            ->assertJsonPath('user.status', 'active')
            ->assertJsonMissing(['token', 'password']);

        $user = PlatformUser::query()->where('email', $email)->firstOrFail();
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('new-password-123', $user->password));

        $invitation = PlatformUserInvitation::query()->where('email', $email)->firstOrFail();
        $this->assertNotNull($invitation->accepted_at);
        $this->assertFalse($invitation->isRedeemable());

        // The accepted account can sign in on the platform host.
        $this->postJson('http://vee-care.test/api/platform/auth/login', [
            'email' => $email,
            'password' => 'new-password-123',
        ])->assertOk();

        $audit = PlatformAuditLog::query()->where('event', 'platform_user.invitation_accepted')->firstOrFail();
        $this->assertSame($user->id, $audit->subject_user_id);
        $this->assertArrayNotHasKey('password', $audit->metadata);
        $this->assertArrayNotHasKey('token', $audit->metadata);
    }

    public function test_used_token_is_rejected(): void
    {
        $token = $this->inviteToken(['name' => 'Admin', 'email' => $this->emailFor('admin'), 'role' => 'platform_admin']);

        $this->accept($token)->assertOk();
        $this->accept($token)
            ->assertStatus(422)
            ->assertJsonValidationErrors('token');
    }

    public function test_expired_token_is_rejected(): void
    {
        $email = $this->emailFor('admin');
        $token = $this->inviteToken(['name' => 'Admin', 'email' => $email, 'role' => 'platform_admin']);

        PlatformUserInvitation::query()->where('email', $email)->update(['expires_at' => now()->subDay()]);

        $this->accept($token)
            ->assertStatus(422)
            ->assertJsonValidationErrors('token');
    }

    public function test_unknown_token_is_rejected_with_generic_error(): void
    {
        $this->accept(Str::random(64))
            ->assertStatus(422)
            ->assertJsonValidationErrors('token')
            ->assertJsonPath('errors.token.0', 'This invitation is invalid or has expired.');
    }

    public function test_accept_fails_when_the_invited_email_already_has_a_platform_user(): void
    {
        $this->platformUser('platform_admin', 'existing.admin@vee-care.test');

        PlatformUserInvitation::query()->create([
            'email' => 'existing.admin@vee-care.test',
            'role' => 'platform_admin',
            'token_hash' => hash('sha256', 'manual-token'),
            'expires_at' => now()->addDay(),
        ]);

        $this->accept('manual-token')
            ->assertStatus(422)
            ->assertJsonValidationErrors('token');
    }

    public function test_invitation_can_be_revoked_before_acceptance(): void
    {
        $email = $this->emailFor('admin');
        $token = $this->inviteToken(['name' => 'Admin', 'email' => $email, 'role' => 'platform_admin']);
        $invitation = PlatformUserInvitation::query()->where('email', $email)->firstOrFail();

        $this->withToken($this->platformToken('platform_super_admin'))
            ->deleteJson('http://vee-care.test/api/platform/users/invitations/'.$invitation->id)
            ->assertOk();

        $this->assertDatabaseMissing('platform_user_invitations', ['id' => $invitation->id]);

        $this->accept($token)->assertStatus(422);

        $audit = $this->lastAudit();
        $this->assertSame('platform_user.invitation_revoked', $audit->event);
        $this->assertSame($email, $audit->metadata['email']);
    }

    public function test_admin_cannot_revoke_a_super_admin_invitation(): void
    {
        $email = $this->emailFor('super');
        $this->inviteToken(['name' => 'Super', 'email' => $email, 'role' => 'platform_super_admin'], $this->platformToken('platform_super_admin'));
        $invitation = PlatformUserInvitation::query()->where('email', $email)->firstOrFail();

        $this->withToken($this->platformToken('platform_admin'))
            ->deleteJson('http://vee-care.test/api/platform/users/invitations/'.$invitation->id)
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Invariants: escalation, self-demotion/deactivation, last super-admin
    |--------------------------------------------------------------------------
    */

    public function test_admin_cannot_escalate_a_user_to_super_admin(): void
    {
        $target = $this->platformUser('platform_admin', 'target.admin@vee-care.test');

        $this->withToken($this->platformToken('platform_admin'))
            ->patchJson('http://vee-care.test/api/platform/users/'.$target->id, ['role' => 'platform_super_admin'])
            ->assertForbidden();

        $target->refresh();
        $this->assertSame('platform_admin', $target->role);
    }

    public function test_admin_cannot_manage_a_super_admin(): void
    {
        $super = $this->platformUser('platform_super_admin', 'boss.super@vee-care.test');

        $this->withToken($this->platformToken('platform_admin'))
            ->patchJson('http://vee-care.test/api/platform/users/'.$super->id, ['name' => 'Hacked'])
            ->assertForbidden();

        $this->withToken($this->platformToken('platform_admin'))
            ->postJson('http://vee-care.test/api/platform/users/'.$super->id.'/deactivate')
            ->assertForbidden();
    }

    public function test_super_admin_can_change_roles_and_audits_role_change(): void
    {
        $target = $this->platformUser('platform_admin', 'promote.me@vee-care.test');

        $this->withToken($this->platformToken('platform_super_admin'))
            ->patchJson('http://vee-care.test/api/platform/users/'.$target->id, ['role' => 'platform_super_admin'])
            ->assertOk()
            ->assertJsonPath('data.role', 'platform_super_admin');

        $audit = $this->lastAudit();
        $this->assertSame('platform_user.role_changed', $audit->event);
        $this->assertSame('platform_admin', $audit->metadata['from']);
        $this->assertSame('platform_super_admin', $audit->metadata['to']);
    }

    public function test_self_demotion_is_blocked(): void
    {
        $actor = $this->platformUser('platform_super_admin', 'self.demote@vee-care.test');
        $token = $actor->createToken('platform-web')->plainTextToken;

        $this->withToken($token)
            ->patchJson('http://vee-care.test/api/platform/users/'.$actor->id, ['role' => 'platform_admin'])
            ->assertStatus(422);

        $actor->refresh();
        $this->assertSame('platform_super_admin', $actor->role);
    }

    public function test_self_deactivation_is_blocked(): void
    {
        $actor = $this->platformUser('platform_super_admin', 'self.off@vee-care.test');
        $token = $actor->createToken('platform-web')->plainTextToken;

        $this->withToken($token)
            ->postJson('http://vee-care.test/api/platform/users/'.$actor->id.'/deactivate')
            ->assertStatus(422);

        $this->assertTrue($actor->refresh()->is_active);
    }

    public function test_last_active_super_admin_cannot_be_demoted_or_deactivated(): void
    {
        $actor = $this->platformUser('platform_super_admin', 'only.super@vee-care.test');
        $token = $actor->createToken('platform-web')->plainTextToken;

        $this->withToken($token)
            ->patchJson('http://vee-care.test/api/platform/users/'.$actor->id, ['role' => 'platform_admin'])
            ->assertStatus(422);

        $this->withToken($token)
            ->postJson('http://vee-care.test/api/platform/users/'.$actor->id.'/deactivate')
            ->assertStatus(422);

        $this->assertTrue($actor->refresh()->is_active);
        $this->assertSame('platform_super_admin', $actor->role);
    }

    public function test_super_admin_can_be_demoted_when_another_super_admin_remains(): void
    {
        $this->platformUser('platform_super_admin', 'keeper.super@vee-care.test');
        $target = $this->platformUser('platform_super_admin', 'removable.super@vee-care.test');

        $this->withToken($this->platformToken('platform_super_admin'))
            ->patchJson('http://vee-care.test/api/platform/users/'.$target->id, ['role' => 'platform_admin'])
            ->assertOk()
            ->assertJsonPath('data.role', 'platform_admin');
    }

    /*
    |--------------------------------------------------------------------------
    | Activation / deactivation
    |--------------------------------------------------------------------------
    */

    public function test_deactivate_revokes_tokens_and_blocks_login(): void
    {
        $target = $this->platformUser('platform_admin', 'deactivate.me@vee-care.test');
        $targetToken = $target->createToken('platform-web')->plainTextToken;

        $this->withToken($this->platformToken('platform_super_admin'))
            ->postJson('http://vee-care.test/api/platform/users/'.$target->id.'/deactivate')
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        // The deactivated user's token is now invalid...
        $this->withToken($targetToken)
            ->getJson('http://vee-care.test/api/platform/me')
            ->assertUnauthorized();

        // ...and sign-in is blocked.
        $this->postJson('http://vee-care.test/api/platform/auth/login', [
            'email' => 'deactivate.me@vee-care.test',
            'password' => 'password123',
        ])->assertStatus(422);

        $audit = $this->lastAudit();
        $this->assertSame('platform_user.deactivated', $audit->event);
        $this->assertSame($target->id, $audit->subject_user_id);
    }

    public function test_activate_restores_login(): void
    {
        $target = $this->platformUser('platform_admin', 'reactivate.me@vee-care.test');
        $target->update(['is_active' => false]);

        $this->withToken($this->platformToken('platform_super_admin'))
            ->postJson('http://vee-care.test/api/platform/users/'.$target->id.'/activate')
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->postJson('http://vee-care.test/api/platform/auth/login', [
            'email' => 'reactivate.me@vee-care.test',
            'password' => 'password123',
        ])->assertOk();

        $audit = PlatformAuditLog::query()->where('event', 'platform_user.activated')->firstOrFail();
    }

    public function test_admin_can_deactivate_another_admin(): void
    {
        $target = $this->platformUser('platform_admin', 'peer.admin@vee-care.test');

        $this->withToken($this->platformToken('platform_admin'))
            ->postJson('http://vee-care.test/api/platform/users/'.$target->id.'/deactivate')
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');
    }

    /*
    |--------------------------------------------------------------------------
    | Rate limiting
    |--------------------------------------------------------------------------
    */

    public function test_invitation_accept_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->accept(Str::random(64))->assertStatus(422);
        }

        $this->accept(Str::random(64))->assertStatus(429);
    }

    public function test_invite_endpoint_is_rate_limited(): void
    {
        Mail::fake();

        $token = $this->platformToken('platform_super_admin');

        for ($i = 0; $i < 10; $i++) {
            $this->invite([
                'name' => 'Bulk '.$i,
                'email' => 'bulk.'.$i.'.'.Str::random(6).'@vee-care.test',
                'role' => 'platform_admin',
            ], $token)->assertCreated();
        }

        $this->invite([
            'name' => 'Bulk 10',
            'email' => 'bulk.10.'.Str::random(6).'@vee-care.test',
            'role' => 'platform_admin',
        ], $token)->assertStatus(429);
    }

    /*
    |--------------------------------------------------------------------------
    | Boundary isolation
    |--------------------------------------------------------------------------
    */

    public function test_platform_users_never_appear_in_tenant_databases(): void
    {
        $this->platformUser('platform_super_admin', 'control.only@vee-care.test');

        $tenant = $this->provisionTenant('hospital-one');

        $this->connectToTenant($tenant);
        $this->assertSame(0, \App\Models\User::query()->where('email', 'control.only@vee-care.test')->count());
        $this->disconnectFromTenant();

        $this->assertSame(1, PlatformUser::query()->where('email', 'control.only@vee-care.test')->count());
    }

    public function test_invited_email_cannot_accept_as_a_tenant_user(): void
    {
        $email = $this->emailFor('admin');
        $token = $this->inviteToken(['name' => 'Admin', 'email' => $email, 'role' => 'platform_admin']);

        $this->accept($token)->assertOk();

        // The platform user exists only on the control plane.
        $this->assertSame(1, PlatformUser::query()->where('email', $email)->count());

        $tenant = $this->provisionTenant('hospital-two');
        $this->connectToTenant($tenant);
        $this->assertSame(0, \App\Models\User::query()->where('email', $email)->count());
    }

    public function test_platform_token_never_authenticates_on_a_tenant_host(): void
    {
        $this->provisionTenant('hospital-one');

        $this->withToken($this->platformToken('platform_super_admin'))
            ->getJson('http://hospital-one.vee-care.test/api/auth/me')
            ->assertUnauthorized();
    }
}
