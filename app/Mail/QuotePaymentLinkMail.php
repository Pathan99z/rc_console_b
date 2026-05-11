<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QuotePaymentLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $quoteNumber,
        public readonly string $customerName,
        public readonly string $total,
        public readonly ?string $currencyCode,
        public readonly string $paymentUrl,
        public readonly string $viewUrl,
        public readonly ?string $messageText = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Payment link for quote {$this->quoteNumber}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.quote-payment-link',
            with: [
                'quoteNumber' => $this->quoteNumber,
                'customerName' => $this->customerName,
                'total' => $this->total,
                'currencyCode' => $this->currencyCode,
                'paymentUrl' => $this->paymentUrl,
                'viewUrl' => $this->viewUrl,
                'messageText' => $this->messageText,
            ]
        );
    }
}
