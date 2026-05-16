<?php

namespace App\Support\OrganizationMail;

/**
 * Resolved SMTP configuration used by {@see \App\Mail\Transport\OrganizationResolvingTransport}.
 */
final class ResolvedSmtpConfiguration
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly ?string $username,
        public readonly ?string $password,
        public readonly ?string $encryption,
        public readonly ?string $fromAddress,
        public readonly ?string $fromName,
        public readonly ?string $replyTo,
        public readonly ?int $sourceOrganizationId,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toDebugPayload(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'encryption' => $this->encryption,
            'username' => $this->username,
            'has_password' => $this->password !== null && $this->password !== '',
            'from_address' => $this->fromAddress,
            'from_name' => $this->fromName,
            'reply_to' => $this->replyTo,
            'source_organization_id' => $this->sourceOrganizationId,
        ];
    }
}
