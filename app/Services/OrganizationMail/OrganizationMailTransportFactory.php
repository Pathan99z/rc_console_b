<?php

namespace App\Services\OrganizationMail;

/**
 * Delegates SMTP Symfony transport creation from resolved configuration.
 */
class OrganizationMailTransportFactory
{
    public function __construct(private readonly OrganizationMailResolverService $resolver) {}

    public function create(\App\Support\OrganizationMail\ResolvedSmtpConfiguration $config): \Symfony\Component\Mailer\Transport\TransportInterface
    {
        return $this->resolver->createTransportForResolved($config);
    }
}
