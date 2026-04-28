<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QuoteSharedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $quoteNumber,
        public readonly string $customerName,
        public readonly string $total,
        public readonly ?string $currencyCode,
        public readonly string $viewUrl,
        public readonly string $acceptUrl,
        public readonly string $rejectUrl,
        public readonly ?string $messageText = null,
        public readonly ?string $pdfBinary = null,
        public readonly ?string $pdfFileName = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Quote {$this->quoteNumber} shared with you",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.quote-shared',
            with: [
                'quoteNumber' => $this->quoteNumber,
                'customerName' => $this->customerName,
                'total' => $this->total,
                'currencyCode' => $this->currencyCode,
                'viewUrl' => $this->viewUrl,
                'acceptUrl' => $this->acceptUrl,
                'rejectUrl' => $this->rejectUrl,
                'messageText' => $this->messageText,
            ]
        );
    }

    public function attachments(): array
    {
        if ($this->pdfBinary === null || $this->pdfFileName === null) {
            return [];
        }

        return [
            Attachment::fromData(fn () => $this->pdfBinary, $this->pdfFileName)
                ->withMime('application/pdf'),
        ];
    }
}
