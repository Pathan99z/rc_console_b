<?php

declare(strict_types=1);

namespace App\Listeners\Cache;

use App\Events\Notifications\ContactAssigned;
use App\Events\Notifications\ContactReassigned;
use App\Events\Notifications\DealAssigned;
use App\Events\Notifications\DealLost;
use App\Events\Notifications\DealOwnerChanged;
use App\Events\Notifications\DealStageChanged;
use App\Events\Notifications\DealWon;
use App\Events\Notifications\LicenseActivatedEvent;
use App\Events\Notifications\LicenseAllocated;
use App\Events\Notifications\OrganizationCreditLimitChanged;
use App\Events\Notifications\PartnerApproved;
use App\Events\Notifications\PartnerInvitationAccepted;
use App\Events\Notifications\PartnerOrganizationSubmittedForReview;
use App\Events\Notifications\PartnerRejected;
use App\Events\Notifications\PartnerSuspended;
use App\Events\Notifications\PaymentLinkInitiated;
use App\Events\Notifications\QuoteAccepted;
use App\Events\Notifications\QuotePaymentFailed;
use App\Events\Notifications\QuotePaymentSucceeded;
use App\Events\Notifications\QuoteRejected;
use App\Events\Notifications\QuoteSent;
use App\Events\Notifications\ResellerApproved;
use App\Events\Notifications\ResellerInvitationAccepted;
use App\Events\Notifications\ResellerOrganizationSubmittedForReview;
use App\Events\Notifications\ResellerRejected;
use App\Events\Notifications\ResellerSuspended;
use App\Events\Notifications\TaskAssigned;
use App\Events\Notifications\TaskCompleted;
use App\Events\Notifications\TaskReassigned;
use App\Events\Notifications\UserAccessRevoked;
use App\Events\Notifications\UserInvited;
use App\Events\Notifications\UserRoleChanged;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\LicenseEntitlement;
use App\Models\Organization;
use App\Models\Quote;
use App\Models\Task;
use App\Models\User;
use App\Services\Cache\CacheInvalidationService;

/**
 * Invalidates enterprise caches when domain events fire (after DB commit).
 */
final class InvalidateCachesOnDomainEvents
{
    public function __construct(private readonly CacheInvalidationService $invalidation) {}

    public function handleQuoteSent(QuoteSent $event): void
    {
        $this->invalidateQuote($event->quoteId);
    }

    public function handleQuoteAccepted(QuoteAccepted $event): void
    {
        $this->invalidateQuote($event->quoteId);
    }

    public function handleQuoteRejected(QuoteRejected $event): void
    {
        $this->invalidateQuote($event->quoteId);
    }

    public function handleQuotePaymentSucceeded(QuotePaymentSucceeded $event): void
    {
        $this->invalidateQuote($event->quoteId);
    }

    public function handleQuotePaymentFailed(QuotePaymentFailed $event): void
    {
        $this->invalidateQuote($event->quoteId);
    }

    public function handlePaymentLinkInitiated(PaymentLinkInitiated $event): void
    {
        $this->invalidateQuote($event->quoteId);
    }

    public function handleDealWon(DealWon $event): void
    {
        $this->invalidateDeal($event->dealId);
    }

    public function handleDealLost(DealLost $event): void
    {
        $this->invalidateDeal($event->dealId);
    }

    public function handleDealStageChanged(DealStageChanged $event): void
    {
        $this->invalidateDeal($event->dealId);
    }

    public function handleDealAssigned(DealAssigned $event): void
    {
        $this->invalidateDeal($event->dealId);
    }

    public function handleDealOwnerChanged(DealOwnerChanged $event): void
    {
        $this->invalidateDeal($event->dealId);
    }

    public function handleContactAssigned(ContactAssigned $event): void
    {
        $this->invalidateContact($event->contactId, $event->tenantId);
    }

    public function handleContactReassigned(ContactReassigned $event): void
    {
        $this->invalidateContact($event->contactId, $event->tenantId);
    }

    public function handleLicenseAllocated(LicenseAllocated $event): void
    {
        $this->invalidateLicenseEntitlement($event->entitlementId);
    }

    public function handleLicenseActivated(LicenseActivatedEvent $event): void
    {
        $this->invalidateLicenseEntitlement($event->entitlementId);
    }

    public function handlePartnerApproved(PartnerApproved $event): void
    {
        $this->invalidateOrganization($event->organizationId);
    }

    public function handlePartnerRejected(PartnerRejected $event): void
    {
        $this->invalidateOrganization($event->organizationId);
    }

    public function handlePartnerSuspended(PartnerSuspended $event): void
    {
        $this->invalidateOrganization($event->organizationId);
    }

    public function handleResellerApproved(ResellerApproved $event): void
    {
        $this->invalidateOrganization($event->organizationId);
    }

    public function handleResellerRejected(ResellerRejected $event): void
    {
        $this->invalidateOrganization($event->organizationId);
    }

    public function handleResellerSuspended(ResellerSuspended $event): void
    {
        $this->invalidateOrganization($event->organizationId);
    }

    public function handlePartnerInvitationAccepted(PartnerInvitationAccepted $event): void
    {
        $this->invalidateOrganization($event->organizationId);
    }

    public function handleResellerInvitationAccepted(ResellerInvitationAccepted $event): void
    {
        $this->invalidateOrganization($event->organizationId);
    }

    public function handlePartnerSubmitted(PartnerOrganizationSubmittedForReview $event): void
    {
        $this->invalidateOrganization($event->organizationId);
    }

    public function handleResellerSubmitted(ResellerOrganizationSubmittedForReview $event): void
    {
        $this->invalidateOrganization($event->organizationId);
    }

    public function handleCreditLimitChanged(OrganizationCreditLimitChanged $event): void
    {
        $this->invalidateOrganization($event->organizationId);
    }

    public function handleUserRoleChanged(UserRoleChanged $event): void
    {
        $user = User::query()->withoutGlobalScopes()->find($event->subjectUserId);
        if ($user !== null) {
            $this->invalidation->afterUserPermissionMutation($user);
        }
    }

    public function handleUserAccessRevoked(UserAccessRevoked $event): void
    {
        $user = User::query()->withoutGlobalScopes()->find($event->subjectUserId);
        if ($user !== null) {
            $this->invalidation->afterUserPermissionMutation($user);
        }
    }

    public function handleUserInvited(UserInvited $event): void
    {
        $user = User::query()->withoutGlobalScopes()->find($event->createdUserId);
        if ($user !== null) {
            $this->invalidation->afterUserPermissionMutation($user);
        }
    }

    public function handleTaskAssigned(TaskAssigned $event): void
    {
        $this->invalidateTask($event->taskId);
    }

    public function handleTaskReassigned(TaskReassigned $event): void
    {
        $this->invalidateTask($event->taskId);
    }

    public function handleTaskCompleted(TaskCompleted $event): void
    {
        $this->invalidateTaskUser($event->completedByUserId);
    }

    private function invalidateQuote(int $quoteId): void
    {
        $quote = Quote::query()->find($quoteId);
        if ($quote === null) {
            return;
        }

        $this->invalidation->afterQuoteMutation(
            (int) $quote->tenant_id,
            $quote->channel_organization_id !== null ? (int) $quote->channel_organization_id : null
        );
    }

    private function invalidateDeal(int $dealId): void
    {
        $deal = Deal::query()->find($dealId);
        if ($deal === null) {
            return;
        }

        $this->invalidation->afterDealMutation(
            (int) $deal->tenant_id,
            $deal->channel_organization_id !== null ? (int) $deal->channel_organization_id : null
        );
    }

    private function invalidateContact(int $contactId, int $tenantId): void
    {
        $contact = Contact::query()->where('tenant_id', $tenantId)->find($contactId);
        $channelOrgId = $contact?->channel_organization_id !== null
            ? (int) $contact->channel_organization_id
            : null;

        $this->invalidation->afterCrmMutation($tenantId, $channelOrgId);
    }

    private function invalidateOrganization(int $organizationId): void
    {
        $org = Organization::query()->withoutGlobalScopes()->find($organizationId);
        if ($org === null) {
            return;
        }

        $this->invalidation->afterOrganizationMutation((int) $org->tenant_id, $organizationId);
    }

    private function invalidateTask(int $taskId): void
    {
        $task = Task::query()->find($taskId);
        if ($task === null || $task->assigned_user_id === null) {
            return;
        }

        $this->invalidateTaskUser((int) $task->assigned_user_id);
    }

    private function invalidateTaskUser(int $userId): void
    {
        $user = User::query()->withoutGlobalScopes()->find($userId);
        if ($user !== null) {
            $this->invalidation->afterNotificationMutation($user);
        }
    }

    private function invalidateLicenseEntitlement(int $entitlementId): void
    {
        $row = LicenseEntitlement::query()->find($entitlementId);
        if ($row === null) {
            return;
        }

        $this->invalidation->afterLicenseMutation(
            (int) $row->tenant_id,
            (int) $row->holder_organization_id
        );
    }
}
