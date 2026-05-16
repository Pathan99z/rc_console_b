<?php

namespace App\Services\OrganizationMail;

use App\Models\Organization;
use App\Models\OrganizationEmailSetting;
use App\Models\User;
use App\Repositories\OrganizationRepository;
use App\Services\Payment\PaymentSecretEncrypter;
use App\Support\OrganizationMail\OrganizationMailContext;
use App\Support\OrganizationMail\ResolvedSmtpConfiguration;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mailer\Transport\Dsn;

class OrganizationMailResolverService
{
    public function __construct(
        private readonly OrganizationRepository $organizationRepository,
        private readonly PaymentSecretEncrypter $encrypter,
    ) {}

    /**
     * Primary organization used for hierarchical SMTP when sending as this user (notifications, etc.).
     */
    public function resolveDefaultOrganizationIdForUser(User $user): ?int
    {
        $primary = $user->primaryOrganizationId();
        if ($primary !== null) {
            return $primary;
        }

        return $this->organizationRepository->firstCompanyOrganizationIdForTenant((int) $user->tenant_id);
    }

    /**
     * Resolve SMTP settings using hierarchy walk (current org → parents → env fallback via transport).
     */
    public function resolveFromContext(): ?ResolvedSmtpConfiguration
    {
        $tenantId = OrganizationMailContext::currentTenantId();
        $organizationId = OrganizationMailContext::currentOrganizationId();

        if ($tenantId === null || $organizationId === null) {
            return null;
        }

        return $this->resolveForTenantOrganization($tenantId, $organizationId);
    }

    public function resolveForTenantOrganization(int $tenantId, int $organizationId): ?ResolvedSmtpConfiguration
    {
        $setting = $this->resolveActiveSettingWalkingHierarchy($tenantId, $organizationId);
        if (! $setting) {
            return null;
        }

        $plainPassword = $this->encrypter->decrypt($setting->encrypted_password);

        return new ResolvedSmtpConfiguration(
            host: (string) $setting->host,
            port: (int) $setting->port,
            username: $setting->username,
            password: $plainPassword,
            encryption: $setting->encryption,
            fromAddress: $setting->from_address,
            fromName: $setting->from_name,
            replyTo: $setting->reply_to,
            sourceOrganizationId: (int) $setting->organization_id,
        );
    }

    private function resolveActiveSettingWalkingHierarchy(int $tenantId, int $startOrganizationId): ?OrganizationEmailSetting
    {
        $currentId = $startOrganizationId;

        while ($currentId !== null && $currentId > 0) {
            $org = $this->organizationRepository->findByIdInTenant($currentId, $tenantId);
            if (! $org) {
                break;
            }

            $row = OrganizationEmailSetting::query()
                ->where('tenant_id', $tenantId)
                ->where('organization_id', $currentId)
                ->where('is_active', true)
                ->first();

            if ($row && $this->rowIsSendable($row)) {
                return $row;
            }

            $parentId = $org->parent_organization_id ? (int) $org->parent_organization_id : null;
            $currentId = $parentId;
        }

        return null;
    }

    private function rowIsSendable(OrganizationEmailSetting $row): bool
    {
        return $row->host !== null && trim((string) $row->host) !== ''
            && $row->port !== null && (int) $row->port > 0;
    }

    /**
     * Builds the Symfony SMTP transport for a resolved configuration.
     */
    public function createTransportForResolved(ResolvedSmtpConfiguration $config): TransportInterface
    {
        $encryption = strtolower((string) ($config->encryption ?? ''));
        $scheme = ($config->port === 465 || $encryption === 'ssl') ? 'smtps' : 'smtp';

        $factory = new EsmtpTransportFactory;

        $dsnConfig = [];
        if ($encryption === 'tls' || $encryption === 'starttls') {
            $dsnConfig['encryption'] = 'tls';
        }

        return $factory->create(new Dsn(
            $scheme,
            $config->host,
            $config->username ?: '',
            $config->password ?: '',
            $config->port,
            $dsnConfig
        ));
    }
}
