<?php

namespace App\Mail\Transport;

use App\Support\OrganizationMail\ResolvedSmtpConfiguration;
use Illuminate\Mail\MailManager;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * SMTP transport that resolves hierarchical organization mail settings when sending,
 * falling back to standard {@see MailManager} env SMTP configuration.
 */
class OrganizationResolvingTransport implements TransportInterface
{
    /**
     * @param  array<string, mixed>  $mailerConfig
     */
    public function __construct(
        private readonly array $mailerConfig,
        private readonly \App\Services\OrganizationMail\OrganizationMailResolverService $resolver,
        private readonly MailManager $mailManager,
    ) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $resolved = $this->resolver->resolveFromContext();

        if ($resolved instanceof ResolvedSmtpConfiguration) {
            $this->applyEnvelopeDefaults($message, $resolved);
            $transport = $this->resolver->createTransportForResolved($resolved);

            return $transport->send($message, $envelope);
        }

        return $this->fallbackEnvTransport()->send($message, $envelope);
    }

    private function fallbackEnvTransport(): TransportInterface
    {
        $config = array_merge($this->mailerConfig, ['transport' => 'smtp']);

        /** @var \Symfony\Component\Mailer\Transport\TransportInterface $t */
        $t = $this->mailManager->createSymfonyTransport($config);

        return $t;
    }

    private function applyEnvelopeDefaults(RawMessage $message, ResolvedSmtpConfiguration $resolved): void
    {
        if (! $message instanceof Email) {
            return;
        }

        if ($resolved->fromAddress !== null && $resolved->fromAddress !== '') {
            $message->from(new Address($resolved->fromAddress, (string) ($resolved->fromName ?? '')));
        }

        if ($resolved->replyTo !== null && $resolved->replyTo !== '') {
            $message->replyTo($resolved->replyTo);
        }
    }

    public function __toString(): string
    {
        return 'organization-resolving';
    }
}
