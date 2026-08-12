<?php

namespace App\Mail;

use App\Models\PlatformUserInvitation as PlatformUserInvitationModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers the single-use platform-administrator invitation to the invitee.
 *
 * Only the acceptance URL is placed in the email body; the raw token never
 * appears in logs (it is embedded in the URL) and no password is ever
 * generated or transmitted. The invitee's user row is created on acceptance.
 */
class PlatformUserInvitation extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public PlatformUserInvitationModel $invitation,
        public string $acceptUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            to: [$this->invitation->email],
            subject: 'Invitation to manage the Vee-Care platform',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.platform-user-invitation',
            with: [
                'expiresAt' => $this->invitation->expires_at,
                'acceptUrl' => $this->acceptUrl,
            ],
        );
    }
}
