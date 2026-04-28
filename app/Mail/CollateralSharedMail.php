<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CollateralSharedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $productName,
        public readonly string $collateralName,
        public readonly string $signedUrl,
        public readonly ?string $messageText = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Document shared with you',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.collateral-shared',
            with: [
                'productName' => $this->productName,
                'collateralName' => $this->collateralName,
                'signedUrl' => $this->signedUrl,
                'messageText' => $this->messageText,
            ]
        );
    }
}
