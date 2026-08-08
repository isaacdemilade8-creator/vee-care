<?php

namespace App\Mail;

use App\Models\HospitalAdminInvitation as HospitalAdminInvitationModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers the single-use hospital-admin invitation to the applicant.
 *
 * Only the acceptance URL is placed in the email body; the raw token never
 * appears in logs (it is embedded in the URL) and no password is ever
 * generated or transmitted.
 */
class HospitalAdminInvitation extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public HospitalAdminInvitationModel $invitation,
        public string $acceptUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            to: [$this->invitation->email],
            subject: 'Set up your Vee-Care hospital administrator account',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.hospital-admin-invitation',
            with: [
                'hospitalName' => $this->invitation->application->hospital_name,
                'expiresAt' => $this->invitation->expires_at,
                'acceptUrl' => $this->acceptUrl,
            ],
        );
    }
}
