<?php

namespace App\Mail\Prm;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PartnerInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $organizationDisplayName,
        public string $acceptUrl,
        public string $invitedRoleLabel,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Partner portal invitation',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.prm.partner-invitation',
        );
    }
}
