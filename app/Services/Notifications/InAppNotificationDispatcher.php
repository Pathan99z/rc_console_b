<?php

namespace App\Services\Notifications;

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
use App\Support\Notifications\InAppNotificationTemplateKeys;

/**
 * Bridges domain events to persisted inbox rows. Failures must never propagate.
 */
class InAppNotificationDispatcher
{
    public function __construct(
        private readonly InAppNotificationService $service,
        private readonly NotificationRecipientResolver $resolver,
    ) {}

    public function dispatch(object $event): void
    {
        try {
            match (true) {
                $event instanceof ContactAssigned => $this->contactsAssigned($event),
                $event instanceof ContactReassigned => $this->contactsReassigned($event),
                $event instanceof TaskAssigned => $this->tasksAssigned($event),
                $event instanceof TaskReassigned => $this->tasksReassigned($event),
                $event instanceof TaskCompleted => $this->tasksCompleted($event),
                $event instanceof QuoteSent => $this->quotesSent($event),
                $event instanceof QuoteAccepted => $this->quotesAccepted($event),
                $event instanceof QuoteRejected => $this->quotesRejected($event),
                $event instanceof QuotePaymentSucceeded => $this->quotePaymentsSucceeded($event),
                $event instanceof QuotePaymentFailed => $this->quotePaymentsFailed($event),
                $event instanceof PaymentLinkInitiated => $this->paymentsInitiated($event),
                $event instanceof DealAssigned => $this->dealsAssigned($event),
                $event instanceof DealOwnerChanged => $this->dealsOwnerChanged($event),
                $event instanceof DealStageChanged => $this->dealsStageChanged($event),
                $event instanceof DealWon => $this->dealsWon($event),
                $event instanceof DealLost => $this->dealsLost($event),
                $event instanceof PartnerOrganizationSubmittedForReview => $this->partnerSubmitted($event),
                $event instanceof ResellerOrganizationSubmittedForReview => $this->resellerSubmitted($event),
                $event instanceof PartnerApproved => $this->partnerApproved($event),
                $event instanceof PartnerRejected => $this->partnerRejected($event),
                $event instanceof PartnerSuspended => $this->partnerSuspended($event),
                $event instanceof ResellerApproved => $this->resellerApproved($event),
                $event instanceof ResellerRejected => $this->resellerRejected($event),
                $event instanceof ResellerSuspended => $this->resellerSuspended($event),
                $event instanceof PartnerInvitationAccepted => $this->partnerInvitationAccepted($event),
                $event instanceof ResellerInvitationAccepted => $this->resellerInvitationAccepted($event),
                $event instanceof LicenseAllocated => $this->licensesAllocated($event),
                $event instanceof LicenseActivatedEvent => $this->licensesActivated($event),
                $event instanceof UserInvited => $this->usersInvited($event),
                $event instanceof UserRoleChanged => $this->usersRoleChanged($event),
                $event instanceof UserAccessRevoked => $this->usersAccessRevoked($event),
                $event instanceof OrganizationCreditLimitChanged => $this->organizationCreditChanged($event),
                default => null,
            };
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array<string, string|int|float|null>  $metadataCtx
     */
    private function persistBatch(
        int $tenantId,
        string $templateKey,
        array $recipientUserIds,
        ?int $actorUserId,
        ?string $entityType,
        ?int $entityId,
        ?int $organizationId,
        array $metadataCtx,
    ): void {
        $tpl = NotificationTemplateCatalog::render($templateKey, $metadataCtx);
        foreach ($this->resolver->uniqueInts($recipientUserIds) as $rid) {
            $recipient = User::query()->where('tenant_id', $tenantId)->whereKey($rid)->first();
            if (! $recipient || (int) $recipient->status !== User::STATUS_ACTIVE) {
                continue;
            }
            try {
                $this->service->create($recipient, [
                    'tenant_id' => $tenantId,
                    'organization_id' => $organizationId,
                    'recipient_user_id' => $recipient->id,
                    'actor_user_id' => $actorUserId,
                    'notification_type' => $templateKey,
                    'category' => $tpl['category'],
                    'title' => $tpl['title'],
                    'message' => $tpl['message'],
                    'action_url' => $tpl['action_url'],
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'priority' => $tpl['priority'],
                    'metadata' => array_merge(['notification_type' => $templateKey], $metadataCtx),
                ]);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    private function contactsAssigned(ContactAssigned $e): void
    {
        $contact = Contact::query()->where('tenant_id', $e->tenantId)->whereKey($e->contactId)->first();
        if (! $contact || ! $contact->assigned_user_id) {
            return;
        }

        if ($e->actorUserId !== null && (int) $contact->assigned_user_id === $e->actorUserId) {
            return;
        }

        $assignee = User::query()->where('tenant_id', $e->tenantId)->whereKey((int) $contact->assigned_user_id)->first();
        $label = trim((string) (($contact->first_name ?? '').' '.($contact->last_name ?? '')));
        $ctx = [
            'contact_id' => (string) $contact->id,
            'contact_label' => $label !== '' ? $label : '#'.$contact->id,
        ];
        $this->persistBatch(
            $e->tenantId,
            InAppNotificationTemplateKeys::CONTACTS_ASSIGNED,
            [(int) $contact->assigned_user_id],
            $e->actorUserId,
            Contact::class,
            $contact->id,
            $contact->channel_organization_id !== null ? (int) $contact->channel_organization_id : null,
            $ctx,
        );

        $manager = $this->resolver->managerWithinTenant($assignee);
        if ($manager && (int) $manager->id !== (int) $contact->assigned_user_id) {
            $this->persistBatch(
                $e->tenantId,
                InAppNotificationTemplateKeys::CONTACTS_ASSIGNED,
                [(int) $manager->id],
                $e->actorUserId,
                Contact::class,
                $contact->id,
                $contact->channel_organization_id !== null ? (int) $contact->channel_organization_id : null,
                $ctx + ['context' => 'manager'],
            );
        }
    }

    private function contactsReassigned(ContactReassigned $e): void
    {
        $contact = Contact::query()->where('tenant_id', $e->tenantId)->whereKey($e->contactId)->first();
        if (! $contact || ! $e->newAssigneeUserId) {
            return;
        }

        if ($e->actorUserId !== null && $e->newAssigneeUserId === $e->actorUserId) {
            return;
        }

        $assignee = User::query()->where('tenant_id', $e->tenantId)->whereKey($e->newAssigneeUserId)->first();
        $label = trim((string) (($contact->first_name ?? '').' '.($contact->last_name ?? '')));
        $ctx = [
            'contact_id' => (string) $contact->id,
            'contact_label' => $label !== '' ? $label : '#'.$contact->id,
        ];
        $this->persistBatch(
            $e->tenantId,
            InAppNotificationTemplateKeys::CONTACTS_REASSIGNED,
            [$e->newAssigneeUserId],
            $e->actorUserId,
            Contact::class,
            $contact->id,
            $contact->channel_organization_id !== null ? (int) $contact->channel_organization_id : null,
            $ctx,
        );

        $manager = $this->resolver->managerWithinTenant($assignee);
        if ($manager && (int) $manager->id !== $e->newAssigneeUserId) {
            $this->persistBatch(
                $e->tenantId,
                InAppNotificationTemplateKeys::CONTACTS_REASSIGNED,
                [(int) $manager->id],
                $e->actorUserId,
                Contact::class,
                $contact->id,
                $contact->channel_organization_id !== null ? (int) $contact->channel_organization_id : null,
                $ctx + ['context' => 'manager'],
            );
        }
    }

    private function tasksAssigned(TaskAssigned $e): void
    {
        $task = Task::query()->find($e->taskId);
        if (! $task || ! $task->assignee_user_id) {
            return;
        }
        $ctx = [
            'task_id' => (string) $task->id,
            'title' => (string) $task->title,
        ];
        $this->persistBatch(
            (int) $task->tenant_id,
            InAppNotificationTemplateKeys::TASKS_ASSIGNED,
            [(int) $task->assignee_user_id],
            $e->actorUserId,
            Task::class,
            $task->id,
            $task->scope_organization_id !== null ? (int) $task->scope_organization_id : null,
            $ctx,
        );
    }

    private function tasksReassigned(TaskReassigned $e): void
    {
        $task = Task::query()->find($e->taskId);
        if (! $task || ! $task->assignee_user_id) {
            return;
        }
        $ctx = [
            'task_id' => (string) $task->id,
            'title' => (string) $task->title,
        ];
        $this->persistBatch(
            (int) $task->tenant_id,
            InAppNotificationTemplateKeys::TASKS_REASSIGNED,
            [(int) $task->assignee_user_id],
            $e->actorUserId,
            Task::class,
            $task->id,
            $task->scope_organization_id !== null ? (int) $task->scope_organization_id : null,
            $ctx,
        );
    }

    private function tasksCompleted(TaskCompleted $e): void
    {
        $task = Task::query()->find($e->taskId);
        if (! $task || ! $task->created_by_user_id || (int) $task->created_by_user_id === (int) $task->assignee_user_id) {
            return;
        }
        if ((int) $task->created_by_user_id === $e->completedByUserId) {
            return;
        }
        $ctx = [
            'task_id' => (string) $task->id,
            'title' => (string) $task->title,
        ];
        $this->persistBatch(
            (int) $task->tenant_id,
            InAppNotificationTemplateKeys::TASKS_COMPLETED,
            [(int) $task->created_by_user_id],
            $e->completedByUserId,
            Task::class,
            $task->id,
            $task->scope_organization_id !== null ? (int) $task->scope_organization_id : null,
            $ctx,
        );
    }

    private function quotesSent(QuoteSent $e): void
    {
        $quote = Quote::query()->find($e->quoteId);
        if (! $quote) {
            return;
        }

        $ctx = [
            'quote_id' => (string) $quote->id,
            'quote_number' => (string) $quote->quote_number,
        ];
        $this->persistBatch(
            (int) $quote->tenant_id,
            InAppNotificationTemplateKeys::QUOTES_SENT,
            $this->resolver->quoteCreatorAndFinanceStaff($quote),
            $e->actorUserId,
            Quote::class,
            $quote->id,
            $quote->channel_organization_id !== null ? (int) $quote->channel_organization_id : null,
            $ctx,
        );
    }

    private function quotesAccepted(QuoteAccepted $e): void
    {
        $quote = Quote::query()->with('deal')->find($e->quoteId);
        if (! $quote) {
            return;
        }

        $ctx = [
            'quote_id' => (string) $quote->id,
            'quote_number' => (string) $quote->quote_number,
        ];
        $this->persistBatch(
            (int) $quote->tenant_id,
            InAppNotificationTemplateKeys::QUOTES_ACCEPTED,
            $this->resolver->quoteCreatorAndFinanceStaff($quote),
            null,
            Quote::class,
            $quote->id,
            $quote->channel_organization_id !== null ? (int) $quote->channel_organization_id : null,
            $ctx,
        );

        $deal = $quote->deal ? $quote->deal->fresh() : null;
        if ($deal && (int) $deal->status === Deal::STATUS_WON) {
            DealWon::dispatch($deal->id, $quote->id);
        }
    }

    private function quotesRejected(QuoteRejected $e): void
    {
        $quote = Quote::query()->find($e->quoteId);
        if (! $quote) {
            return;
        }
        $ctx = [
            'quote_id' => (string) $quote->id,
            'quote_number' => (string) $quote->quote_number,
        ];
        $this->persistBatch(
            (int) $quote->tenant_id,
            InAppNotificationTemplateKeys::QUOTES_REJECTED,
            $this->resolver->quoteCreatorAndFinanceStaff($quote),
            null,
            Quote::class,
            $quote->id,
            $quote->channel_organization_id !== null ? (int) $quote->channel_organization_id : null,
            $ctx,
        );
    }

    private function quotePaymentsSucceeded(QuotePaymentSucceeded $e): void
    {
        $quote = Quote::query()->find($e->quoteId);
        if (! $quote) {
            return;
        }
        $baseCtx = [
            'quote_id' => (string) $quote->id,
            'quote_number' => (string) $quote->quote_number,
            'payment_record_id' => (string) ($e->paymentRecordId ?? ''),
        ];
        $orgId = $quote->channel_organization_id !== null ? (int) $quote->channel_organization_id : null;
        $this->persistBatch(
            (int) $quote->tenant_id,
            InAppNotificationTemplateKeys::QUOTES_PAYMENT_SUCCESS,
            $this->resolver->quoteCreatorAndFinanceStaff($quote),
            $e->actorUserId,
            Quote::class,
            $quote->id,
            $orgId,
            $baseCtx,
        );

        $this->persistBatch(
            (int) $quote->tenant_id,
            InAppNotificationTemplateKeys::PAYMENTS_SUCCESS,
            $this->resolver->quoteCreatorAndFinanceStaff($quote),
            $e->actorUserId,
            Quote::class,
            $quote->id,
            $orgId,
            $baseCtx,
        );

        $deal = $quote->deal ? $quote->deal->fresh() : null;
        if ($deal && (int) $deal->status === Deal::STATUS_WON) {
            DealWon::dispatch($deal->id, $quote->id);
        }
    }

    private function quotePaymentsFailed(QuotePaymentFailed $e): void
    {
        $quote = Quote::query()->find($e->quoteId);
        if (! $quote) {
            return;
        }
        $baseCtx = [
            'quote_id' => (string) $quote->id,
            'quote_number' => (string) $quote->quote_number,
            'payment_record_id' => (string) ($e->paymentRecordId ?? ''),
        ];
        $orgId = $quote->channel_organization_id !== null ? (int) $quote->channel_organization_id : null;
        $recipients = $this->resolver->quoteCreatorAndFinanceStaff($quote);
        $this->persistBatch(
            (int) $quote->tenant_id,
            InAppNotificationTemplateKeys::QUOTES_PAYMENT_FAILED,
            $recipients,
            null,
            Quote::class,
            $quote->id,
            $orgId,
            $baseCtx,
        );
        $this->persistBatch(
            (int) $quote->tenant_id,
            InAppNotificationTemplateKeys::PAYMENTS_FAILED,
            $recipients,
            null,
            Quote::class,
            $quote->id,
            $orgId,
            $baseCtx,
        );
    }

    private function paymentsInitiated(PaymentLinkInitiated $e): void
    {
        $quote = Quote::query()->find($e->quoteId);
        if (! $quote) {
            return;
        }
        $who = $e->initiatedByUserId ?: (int) $quote->created_by_user_id;
        $audience = $this->resolver->uniqueInts(array_merge(
            [$who],
            $this->resolver->companyAndFinanceUserIds((int) $quote->tenant_id)
        ));
        $ctx = [
            'quote_id' => (string) $quote->id,
            'quote_number' => (string) $quote->quote_number,
            'payment_record_id' => (string) $e->paymentRecordId,
        ];
        $this->persistBatch(
            (int) $quote->tenant_id,
            InAppNotificationTemplateKeys::PAYMENTS_INITIATED,
            $audience,
            $e->initiatedByUserId,
            Quote::class,
            $quote->id,
            $quote->channel_organization_id !== null ? (int) $quote->channel_organization_id : null,
            $ctx,
        );
    }

    private function dealsAssigned(DealAssigned $e): void
    {
        $deal = Deal::query()->find($e->dealId);
        if (! $deal || (int) $deal->owner_user_id === $e->actorUserId) {
            return;
        }

        $ctx = [
            'deal_id' => (string) $deal->id,
            'deal_name' => (string) $deal->name,
        ];
        $partnerOrgId = $deal->partner_organization_id !== null ? (int) $deal->partner_organization_id : null;
        $this->persistBatch(
            (int) $deal->tenant_id,
            InAppNotificationTemplateKeys::DEALS_ASSIGNED,
            [(int) $deal->owner_user_id],
            $e->actorUserId,
            Deal::class,
            $deal->id,
            $partnerOrgId,
            $ctx,
        );
    }

    private function dealsOwnerChanged(DealOwnerChanged $e): void
    {
        $deal = Deal::query()->find($e->dealId);
        if (! $deal) {
            return;
        }

        $ctx = [
            'deal_id' => (string) $deal->id,
            'deal_name' => (string) $deal->name,
            'previous_owner_user_id' => (string) ($e->previousOwnerUserId ?? ''),
        ];
        $partnerOrgId = $deal->partner_organization_id !== null ? (int) $deal->partner_organization_id : null;
        $this->persistBatch(
            (int) $deal->tenant_id,
            InAppNotificationTemplateKeys::DEALS_OWNER_CHANGED,
            [$e->newOwnerUserId],
            $e->actorUserId,
            Deal::class,
            $deal->id,
            $partnerOrgId,
            $ctx,
        );
    }

    private function dealsStageChanged(DealStageChanged $e): void
    {
        if (! $this->stageIsImportant($e->stageName)) {
            return;
        }
        $deal = Deal::query()->find($e->dealId);
        if (! $deal || ! $deal->owner_user_id) {
            return;
        }
        $ctx = [
            'deal_id' => (string) $deal->id,
            'deal_name' => (string) $deal->name,
            'stage_name' => (string) $e->stageName,
        ];
        $partnerOrgId = $deal->partner_organization_id !== null ? (int) $deal->partner_organization_id : null;
        $this->persistBatch(
            (int) $deal->tenant_id,
            InAppNotificationTemplateKeys::DEALS_STAGE_CHANGED,
            $this->resolver->dealOwnerAndFinance($deal),
            null,
            Deal::class,
            $deal->id,
            $partnerOrgId,
            $ctx,
        );
    }

    private function dealsWon(DealWon $e): void
    {
        $deal = Deal::query()->find($e->dealId);
        if (! $deal) {
            return;
        }
        $ctx = [
            'deal_id' => (string) $deal->id,
            'deal_name' => (string) $deal->name,
            'source_quote_id' => $e->sourceQuoteId !== null ? (string) $e->sourceQuoteId : '',
        ];
        $partnerOrgId = $deal->partner_organization_id !== null ? (int) $deal->partner_organization_id : null;
        $recipientIds = $this->resolver->uniqueInts(array_merge(
            $this->resolver->dealOwnerOnlyIds($deal),
            $this->resolver->companyAndFinanceUserIds((int) $deal->tenant_id)
        ));
        $tpl = NotificationTemplateCatalog::render(InAppNotificationTemplateKeys::DEALS_WON, $ctx);
        $bucket = now()->utc()->format('Y-m-d');
        foreach ($recipientIds as $rid) {
            if ($this->service->hasRecentNotificationBucket(
                (int) $deal->tenant_id,
                (int) $rid,
                InAppNotificationTemplateKeys::DEALS_WON,
                Deal::class,
                $deal->id,
                $bucket,
            )) {
                continue;
            }
            $recipient = User::query()->where('tenant_id', $deal->tenant_id)->whereKey($rid)->first();
            if (! $recipient || (int) $recipient->status !== User::STATUS_ACTIVE) {
                continue;
            }
            try {
                $this->service->create($recipient, [
                    'tenant_id' => (int) $deal->tenant_id,
                    'organization_id' => $partnerOrgId,
                    'recipient_user_id' => $recipient->id,
                    'actor_user_id' => null,
                    'notification_type' => InAppNotificationTemplateKeys::DEALS_WON,
                    'category' => $tpl['category'],
                    'title' => $tpl['title'],
                    'message' => $tpl['message'],
                    'action_url' => $tpl['action_url'],
                    'entity_type' => Deal::class,
                    'entity_id' => $deal->id,
                    'priority' => $tpl['priority'],
                    'metadata' => array_merge(['notification_type' => InAppNotificationTemplateKeys::DEALS_WON], $ctx),
                ]);
            } catch (\Throwable $ex) {
                report($ex);
            }
        }
    }

    private function dealsLost(DealLost $e): void
    {
        $deal = Deal::query()->find($e->dealId);
        if (! $deal) {
            return;
        }
        $ctx = [
            'deal_id' => (string) $deal->id,
            'deal_name' => (string) $deal->name,
        ];
        $partnerOrgId = $deal->partner_organization_id !== null ? (int) $deal->partner_organization_id : null;
        $this->persistBatch(
            (int) $deal->tenant_id,
            InAppNotificationTemplateKeys::DEALS_LOST,
            $this->resolver->dealOwnerAndFinance($deal),
            $e->actorUserId,
            Deal::class,
            $deal->id,
            $partnerOrgId,
            $ctx,
        );
    }

    private function stageIsImportant(string $stageName): bool
    {
        $normalized = strtolower((string) preg_replace('/\s+/u', ' ', trim($stageName)));
        foreach (['proposal', 'negotiation'] as $token) {
            if (str_contains($normalized, $token)) {
                return true;
            }
        }

        return false;
    }

    private function partnerSubmitted(PartnerOrganizationSubmittedForReview $e): void
    {
        $org = Organization::query()->find($e->organizationId);
        if (! $org || $org->type !== Organization::TYPE_PARTNER) {
            return;
        }

        $ctx = [
            'organization_id' => (string) $org->id,
            'org_name' => (string) ($org->display_name ?? $org->legal_name ?? ''),
        ];
        $this->persistBatch(
            (int) $org->tenant_id,
            InAppNotificationTemplateKeys::PARTNERS_SUBMITTED,
            $this->resolver->partnerKamAudienceForTenantOrg((int) $org->tenant_id, $org),
            null,
            Organization::class,
            $org->id,
            $org->id,
            $ctx,
        );
    }

    private function resellerSubmitted(ResellerOrganizationSubmittedForReview $e): void
    {
        $org = Organization::query()->find($e->organizationId);
        if (! $org || $org->type !== Organization::TYPE_RESELLER) {
            return;
        }

        $ctx = [
            'organization_id' => (string) $org->id,
            'org_name' => (string) ($org->display_name ?? $org->legal_name ?? ''),
        ];
        $partnerId = $org->parent_organization_id !== null ? (int) $org->parent_organization_id : null;
        $recipients = $partnerId !== null
            ? $this->resolver->uniqueInts(array_merge(
                $this->resolver->adminsForPartnerOrganizationTree((int) $org->tenant_id, $partnerId),
                $this->resolver->companyAndFinanceUserIds((int) $org->tenant_id)
            ))
            : $this->resolver->companyAndFinanceUserIds((int) $org->tenant_id);

        $this->persistBatch(
            (int) $org->tenant_id,
            InAppNotificationTemplateKeys::RESELLERS_SUBMITTED,
            $recipients,
            null,
            Organization::class,
            $org->id,
            $org->id,
            $ctx,
        );
    }

    private function partnerApproved(PartnerApproved $e): void
    {
        $org = Organization::query()->find($e->organizationId);
        if (! $org || $org->type !== Organization::TYPE_PARTNER) {
            return;
        }
        $ctx = ['organization_id' => (string) $org->id, 'org_name' => $this->orgLabel($org)];
        $recipientIds = $this->resolver->uniqueInts(array_merge(
            $this->resolver->adminsForPartnerOrganizationTree((int) $org->tenant_id, $org->id),
            $this->resolver->partnerKamAudienceForTenantOrg((int) $org->tenant_id, $org),
        ));

        $this->persistBatch(
            (int) $org->tenant_id,
            InAppNotificationTemplateKeys::PARTNERS_APPROVED,
            $recipientIds,
            $e->actorUserId,
            Organization::class,
            $org->id,
            $org->id,
            $ctx,
        );
    }

    private function partnerRejected(PartnerRejected $e): void
    {
        $this->simplePartnerAudienceNotify($e->organizationId, $e->actorUserId, InAppNotificationTemplateKeys::PARTNERS_REJECTED);
    }

    private function partnerSuspended(PartnerSuspended $e): void
    {
        $this->simplePartnerAudienceNotify($e->organizationId, $e->actorUserId, InAppNotificationTemplateKeys::PARTNERS_SUSPENDED);
    }

    private function simplePartnerAudienceNotify(int $organizationId, int $actorUserId, string $templateKey): void
    {
        $org = Organization::query()->find($organizationId);
        if (! $org || $org->type !== Organization::TYPE_PARTNER) {
            return;
        }
        $ctx = ['organization_id' => (string) $org->id, 'org_name' => $this->orgLabel($org)];
        $recipientIds = $this->resolver->uniqueInts(array_merge(
            $this->resolver->adminsForPartnerOrganizationTree((int) $org->tenant_id, $org->id),
            $this->resolver->partnerKamAudienceForTenantOrg((int) $org->tenant_id, $org),
        ));
        $this->persistBatch(
            (int) $org->tenant_id,
            $templateKey,
            $recipientIds,
            $actorUserId,
            Organization::class,
            $org->id,
            $org->id,
            $ctx,
        );
    }

    private function resellerApproved(ResellerApproved $e): void
    {
        $org = Organization::query()->find($e->organizationId);
        if (! $org || $org->type !== Organization::TYPE_RESELLER) {
            return;
        }
        $ctx = ['organization_id' => (string) $org->id, 'org_name' => $this->orgLabel($org)];
        $recipients = $this->resolver->resellerStakeholderUserIds((int) $org->tenant_id, $org);
        $this->persistBatch(
            (int) $org->tenant_id,
            InAppNotificationTemplateKeys::RESELLERS_APPROVED,
            $recipients,
            $e->actorUserId,
            Organization::class,
            $org->id,
            $org->id,
            $ctx,
        );
    }

    private function resellerRejected(ResellerRejected $e): void
    {
        $this->simpleResellerStakeholderNotify($e->organizationId, $e->actorUserId, InAppNotificationTemplateKeys::RESELLERS_REJECTED);
    }

    private function resellerSuspended(ResellerSuspended $e): void
    {
        $this->simpleResellerStakeholderNotify($e->organizationId, $e->actorUserId, InAppNotificationTemplateKeys::RESELLERS_SUSPENDED);
    }

    private function simpleResellerStakeholderNotify(int $organizationId, int $actorUserId, string $key): void
    {
        $org = Organization::query()->find($organizationId);
        if (! $org || $org->type !== Organization::TYPE_RESELLER) {
            return;
        }
        $ctx = ['organization_id' => (string) $org->id, 'org_name' => $this->orgLabel($org)];
        $this->persistBatch(
            (int) $org->tenant_id,
            $key,
            $this->resolver->resellerStakeholderUserIds((int) $org->tenant_id, $org),
            $actorUserId,
            Organization::class,
            $org->id,
            $org->id,
            $ctx,
        );
    }

    private function partnerInvitationAccepted(PartnerInvitationAccepted $e): void
    {
        $org = Organization::query()->find($e->organizationId);
        if (! $org || $org->type !== Organization::TYPE_PARTNER) {
            return;
        }
        $accepted = User::query()->find($e->acceptedUserId);
        $ctx = [
            'organization_id' => (string) $org->id,
            'org_name' => $this->orgLabel($org),
            'user_name' => $accepted ? (string) $accepted->name : 'User',
        ];
        $recipients = $this->resolver->uniqueInts(array_merge(
            [$e->acceptedUserId],
            $this->resolver->partnerKamAudienceForTenantOrg((int) $org->tenant_id, $org),
            $this->resolver->adminsForPartnerOrganizationTree((int) $org->tenant_id, $org->id),
        ));
        $this->persistBatch(
            (int) $org->tenant_id,
            InAppNotificationTemplateKeys::PARTNERS_INVITATION_ACCEPTED,
            $recipients,
            null,
            Organization::class,
            $org->id,
            $org->id,
            $ctx,
        );
    }

    private function resellerInvitationAccepted(ResellerInvitationAccepted $e): void
    {
        $org = Organization::query()->find($e->organizationId);
        if (! $org || $org->type !== Organization::TYPE_RESELLER) {
            return;
        }
        $accepted = User::query()->find($e->acceptedUserId);
        $ctx = [
            'organization_id' => (string) $org->id,
            'org_name' => $this->orgLabel($org),
            'user_name' => $accepted ? (string) $accepted->name : 'User',
        ];
        $recipients = $this->resolver->uniqueInts(array_merge(
            [$e->acceptedUserId],
            $this->resolver->resellerStakeholderUserIds((int) $org->tenant_id, $org),
        ));
        $this->persistBatch(
            (int) $org->tenant_id,
            InAppNotificationTemplateKeys::RESELLERS_INVITATION_ACCEPTED,
            $recipients,
            null,
            Organization::class,
            $org->id,
            $org->id,
            $ctx,
        );
    }

    private function licensesAllocated(LicenseAllocated $e): void
    {
        $row = LicenseEntitlement::query()->with('holderOrganization')->find($e->entitlementId);
        if (! $row || ! $row->holderOrganization) {
            return;
        }
        $holder = $row->holderOrganization;
        $kind = $holder->type === Organization::TYPE_RESELLER
            ? InAppNotificationTemplateKeys::LICENSES_ALLOCATED_RESELLER
            : InAppNotificationTemplateKeys::LICENSES_ALLOCATED_PARTNER;

        $ctx = [
            'units' => (string) $row->units_total,
            'org_name' => $this->orgLabel($holder),
            'entitlement_id' => (string) $row->id,
        ];
        $recipients = $this->resolver->uniqueInts(array_merge(
            $this->resolver->companyAndFinanceUserIds((int) $row->tenant_id),
            $holder->type === Organization::TYPE_PARTNER
                ? $this->resolver->adminsForPartnerOrganizationTree((int) $row->tenant_id, $holder->id)
                : $this->resolver->resellerStakeholderUserIds((int) $row->tenant_id, $holder),
        ));
        $this->persistBatch(
            (int) $row->tenant_id,
            $kind,
            $recipients,
            $e->actorUserId,
            LicenseEntitlement::class,
            $row->id,
            $holder->id,
            $ctx,
        );
    }

    private function licensesActivated(LicenseActivatedEvent $e): void
    {
        $row = LicenseEntitlement::query()->with('holderOrganization')->find($e->entitlementId);
        if (! $row || ! $row->holderOrganization) {
            return;
        }

        $holder = $row->holderOrganization;
        $ctx = [
            'units' => (string) $e->units,
            'entitlement_id' => (string) $row->id,
            'activation_id' => (string) ($e->activationId ?? 0),
        ];
        $recipients = match ($holder->type) {
            Organization::TYPE_PARTNER => $this->resolver->adminsForPartnerOrganizationTree((int) $row->tenant_id, $holder->id),
            Organization::TYPE_RESELLER => $this->resolver->resellerAdminsForOrganization((int) $row->tenant_id, $holder->id),
            default => $this->resolver->companyAndFinanceUserIds((int) $row->tenant_id),
        };
        $recipients = $this->resolver->uniqueInts(array_merge(
            $recipients,
            $this->resolver->companyAndFinanceUserIds((int) $row->tenant_id)
        ));

        $this->persistBatch(
            (int) $row->tenant_id,
            InAppNotificationTemplateKeys::LICENSES_ACTIVATED,
            $recipients,
            $e->activatedByUserId,
            LicenseEntitlement::class,
            $row->id,
            $holder->id,
            $ctx,
        );
    }

    private function usersInvited(UserInvited $e): void
    {
        $subject = User::query()->find($e->createdUserId);
        if (! $subject) {
            return;
        }
        $ctx = ['role' => (string) $subject->role];
        $this->persistBatch(
            (int) $subject->tenant_id,
            InAppNotificationTemplateKeys::USERS_INVITED,
            [$subject->id],
            $e->actorUserId,
            User::class,
            $subject->id,
            $subject->primaryOrganizationId(),
            $ctx,
        );
    }

    private function usersRoleChanged(UserRoleChanged $e): void
    {
        $subject = User::query()->find($e->subjectUserId);
        if (! $subject) {
            return;
        }

        $ctx = ['role' => (string) $subject->role];
        $this->persistBatch(
            (int) $subject->tenant_id,
            InAppNotificationTemplateKeys::USERS_ROLE_CHANGED,
            [$subject->id],
            $e->actorUserId,
            User::class,
            $subject->id,
            $subject->primaryOrganizationId(),
            $ctx,
        );
    }

    private function usersAccessRevoked(UserAccessRevoked $e): void
    {
        $subject = User::query()->find($e->subjectUserId);
        if (! $subject) {
            return;
        }

        $ctx = ['status' => $subject->statusLabel()];
        $this->persistBatch(
            (int) $subject->tenant_id,
            InAppNotificationTemplateKeys::USERS_ACCESS_REVOKED,
            [$subject->id],
            $e->actorUserId,
            User::class,
            $subject->id,
            $subject->primaryOrganizationId(),
            $ctx,
        );
    }

    private function organizationCreditChanged(OrganizationCreditLimitChanged $e): void
    {
        $org = Organization::query()->find($e->organizationId);
        if (! $org) {
            return;
        }

        $ctx = [
            'organization_id' => (string) $org->id,
            'org_name' => $this->orgLabel($org),
        ];
        $recipients = $this->resolver->companyAndFinanceUserIds((int) $org->tenant_id);
        if ($org->type === Organization::TYPE_PARTNER) {
            $recipients = array_merge(
                $recipients,
                $this->resolver->adminsForPartnerOrganizationTree((int) $org->tenant_id, $org->id)
            );
        }
        if ($org->type === Organization::TYPE_RESELLER) {
            $recipients = array_merge(
                $recipients,
                $this->resolver->resellerAdminsForOrganization((int) $org->tenant_id, $org->id)
            );
        }
        $this->persistBatch(
            (int) $org->tenant_id,
            InAppNotificationTemplateKeys::PAYMENTS_WALLET_CREDITED,
            $this->resolver->uniqueInts($recipients),
            $e->actorUserId,
            Organization::class,
            $org->id,
            $org->id,
            $ctx,
        );
    }

    public function sendScheduledTaskReminder(Task $task, string $templateKey): void
    {
        if (! $task->assignee_user_id || in_array($task->status, [Task::STATUS_COMPLETED, Task::STATUS_CANCELLED], true)) {
            return;
        }

        $tenantId = (int) $task->tenant_id;
        $assigneeId = (int) $task->assignee_user_id;
        $bucket = now()->utc()->format('Y-m-d');
        if ($this->service->hasRecentNotificationBucket($tenantId, $assigneeId, $templateKey, Task::class, $task->id, $bucket)) {
            return;
        }

        $ctx = [
            'task_id' => (string) $task->id,
            'title' => (string) $task->title,
        ];
        $this->persistBatch(
            $tenantId,
            $templateKey,
            [$assigneeId],
            null,
            Task::class,
            $task->id,
            $task->scope_organization_id !== null ? (int) $task->scope_organization_id : null,
            $ctx,
        );
    }

    private function orgLabel(Organization $org): string
    {
        $name = trim((string) ($org->display_name ?? $org->legal_name ?? ''));

        return $name !== '' ? $name : 'Organization #'.$org->id;
    }
}
