<?php

namespace App\Mail;

use App\Models\UserInvitation as UserInvitationModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers the single-use hospital user invitation to the invitee.
 *
 * Only the acceptance URL is placed in the email body; the raw token never
 * appears in logs (it is embedded in the URL) and no password is ever
 * generated or transmitted. The invitee's user row is created on acceptance.
 */
class TenantUserInvitation extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public UserInvitationModel $invitation,
        public string $acceptUrl,
        public string $tenantName,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            to: [$this->invitation->email],
            subject: 'You are invited to join your hospital on Vee-Care',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.tenant-user-invitation',
            with: [
                'tenantName' => $this->tenantName,
                'expiresAt' => $this->invitation->expires_at,
                'acceptUrl' => $this->acceptUrl,
            ],
        );
    }
}
