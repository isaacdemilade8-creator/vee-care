<?php

namespace Tests\Feature;

use App\Mail\HospitalAdminInvitation as HospitalAdminInvitationMail;
use App\Models\HospitalAdminInvitation;
use App\Models\PlatformUser;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesTenantDatabase;
use Tests\TestCase;

/**
 * Hospital-administrator invitation lifecycle tests.
 *
 * Invitations are single-use and expiring; only their digest is stored, and
 * redemption sets the administrator's password inside the correct tenant only.
 */
class HospitalAdminInvitationTest extends TestCase
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

    protected function submitApplication(array $payload): TestResponse
    {
        return $this->postJson('http://vee-care.test/api/platform/hospital-applications', $payload);
    }

    protected function platformToken(): string
    {
        $user = PlatformUser::query()->where('email', 'operator@vee-care.test')->first()
            ?? PlatformUser::query()->create([
                'name' => 'Platform Operator',
                'email' => 'operator@vee-care.test',
                'password' => Hash::make('password123'),
                'role' => 'platform_super_admin',
            ]);

        return $user->createToken('platform-web')->plainTextToken;
    }

    protected function defaultPayload(array $overrides = []): array
    {
        return [
            'hospital_name' => 'Mercy Hospital',
            'slug' => 'mercy-hospital',
            'type' => 'hospital',
            'contact_name' => 'Jane Doe',
            'contact_email' => 'jane@mercyhospital.test',
            'contact_phone' => '+1000000000',
            'description' => 'A general hospital.',
            ...$overrides,
        ];
    }

    protected function approveApplication(int $applicationId): string
    {
        $response = $this->withToken($this->platformToken())
            ->postJson('http://vee-care.test/api/platform/hospital-applications/'.$applicationId.'/approve')
            ->assertOk();

        return (string) $response->json('invitation.token');
    }

    protected function acceptInvitation(string $token, array $payload = []): TestResponse
    {
        return $this->postJson(
            'http://vee-care.test/api/platform/hospital-applications/invitations/'.$token.'/accept',
            [
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
                ...$payload,
            ],
        );
    }

    /**
     * Redeeming a valid invitation sets the hospital admin's password, marks
     * the invitation used, and lets the admin log in on the tenant host.
     */
    public function test_valid_invitation_sets_password_and_marks_used(): void
    {
        $this->submitApplication($this->defaultPayload())->assertCreated();

        $token = $this->approveApplication(1);

        $response = $this->acceptInvitation($token, ['name' => 'Jane Doe']);

        $response->assertOk()
            ->assertJsonPath('user.email', 'jane@mercyhospital.test')
            ->assertJsonPath('user.role', 'hospital_admin')
            ->assertJsonMissing(['token', 'password']);

        $stored = HospitalAdminInvitation::query()->where('application_id', 1)->firstOrFail();
        $this->assertNotNull($stored->used_at);
        $this->assertTrue($stored->isUsed());
        $this->assertFalse($stored->isRedeemable());

        $this->postJson('http://mercy-hospital.vee-care.test/api/auth/login', [
            'email' => 'jane@mercyhospital.test',
            'password' => 'new-password-123',
        ])->assertOk();
    }

    /**
     * Approving an application delivers the invitation email to the applicant.
     */
    public function test_approval_dispatches_invitation_email_to_applicant(): void
    {
        Mail::fake();

        $this->submitApplication($this->defaultPayload())->assertCreated();

        $this->approveApplication(1);

        Mail::assertQueued(HospitalAdminInvitationMail::class, function (HospitalAdminInvitationMail $mail): bool {
            return $mail->hasTo('jane@mercyhospital.test')
                && str_contains($mail->acceptUrl, '/api/platform/hospital-applications/invitations/');
        });
    }

    /**
     * An invitation can only be redeemed once.
     */
    public function test_used_invitation_cannot_be_redeemed_twice(): void
    {
        $this->submitApplication($this->defaultPayload())->assertCreated();

        $token = $this->approveApplication(1);

        $this->acceptInvitation($token)->assertOk();

        $this->acceptInvitation($token)
            ->assertStatus(422)
            ->assertJsonValidationErrors('token');
    }

    /**
     * An expired invitation cannot be redeemed.
     */
    public function test_expired_invitation_is_rejected(): void
    {
        $this->submitApplication($this->defaultPayload())->assertCreated();

        $this->approveApplication(1);

        HospitalAdminInvitation::query()->where('application_id', 1)->update(['expires_at' => now()->subDay()]);

        $stored = HospitalAdminInvitation::query()->where('application_id', 1)->firstOrFail();
        $this->assertTrue($stored->isExpired());

        $this->acceptInvitation('some-token')
            ->assertStatus(422)
            ->assertJsonValidationErrors('token');
    }

    /**
     * An unknown token is rejected.
     */
    public function test_unknown_token_is_rejected(): void
    {
        $this->submitApplication($this->defaultPayload())->assertCreated();

        $this->acceptInvitation(implode('', array_fill(0, 64, 'x')))
            ->assertStatus(422)
            ->assertJsonValidationErrors('token');
    }

    /**
     * Invitations are scoped to the tenant they were issued for.
     */
    public function test_invitation_is_scoped_to_its_tenant(): void
    {
        $this->submitApplication($this->defaultPayload())->assertCreated();
        $this->submitApplication($this->defaultPayload([
            'hospital_name' => 'Second Hospital',
            'slug' => 'second-hospital',
            'contact_email' => 'admin@secondhospital.test',
        ]))->assertCreated();

        $firstToken = $this->approveApplication(1);
        $secondToken = $this->approveApplication(2);

        $this->assertNotSame($firstToken, $secondToken);

        $this->acceptInvitation($firstToken)->assertOk();

        // The redeemed password works for Hospital One...
        $this->postJson('http://mercy-hospital.vee-care.test/api/auth/login', [
            'email' => 'jane@mercyhospital.test',
            'password' => 'new-password-123',
        ])->assertOk();

        // ...but not for Hospital Two, whose admin is untouched.
        $this->postJson('http://second-hospital.vee-care.test/api/auth/login', [
            'email' => 'admin@secondhospital.test',
            'password' => 'new-password-123',
        ])->assertStatus(422);
    }
}
