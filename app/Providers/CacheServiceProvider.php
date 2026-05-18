<?php

declare(strict_types=1);

namespace App\Providers;

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
use App\Listeners\Cache\InvalidateCachesOnDomainEvents;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class CacheServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $listener = InvalidateCachesOnDomainEvents::class;

        $map = [
            QuoteSent::class => 'handleQuoteSent',
            QuoteAccepted::class => 'handleQuoteAccepted',
            QuoteRejected::class => 'handleQuoteRejected',
            QuotePaymentSucceeded::class => 'handleQuotePaymentSucceeded',
            QuotePaymentFailed::class => 'handleQuotePaymentFailed',
            PaymentLinkInitiated::class => 'handlePaymentLinkInitiated',
            DealWon::class => 'handleDealWon',
            DealLost::class => 'handleDealLost',
            DealStageChanged::class => 'handleDealStageChanged',
            DealAssigned::class => 'handleDealAssigned',
            DealOwnerChanged::class => 'handleDealOwnerChanged',
            ContactAssigned::class => 'handleContactAssigned',
            ContactReassigned::class => 'handleContactReassigned',
            LicenseAllocated::class => 'handleLicenseAllocated',
            LicenseActivatedEvent::class => 'handleLicenseActivated',
            PartnerApproved::class => 'handlePartnerApproved',
            PartnerRejected::class => 'handlePartnerRejected',
            PartnerSuspended::class => 'handlePartnerSuspended',
            ResellerApproved::class => 'handleResellerApproved',
            ResellerRejected::class => 'handleResellerRejected',
            ResellerSuspended::class => 'handleResellerSuspended',
            PartnerInvitationAccepted::class => 'handlePartnerInvitationAccepted',
            ResellerInvitationAccepted::class => 'handleResellerInvitationAccepted',
            PartnerOrganizationSubmittedForReview::class => 'handlePartnerSubmitted',
            ResellerOrganizationSubmittedForReview::class => 'handleResellerSubmitted',
            OrganizationCreditLimitChanged::class => 'handleCreditLimitChanged',
            UserRoleChanged::class => 'handleUserRoleChanged',
            UserAccessRevoked::class => 'handleUserAccessRevoked',
            UserInvited::class => 'handleUserInvited',
            TaskAssigned::class => 'handleTaskAssigned',
            TaskReassigned::class => 'handleTaskReassigned',
            TaskCompleted::class => 'handleTaskCompleted',
        ];

        foreach ($map as $eventClass => $method) {
            Event::listen($eventClass, [$listener, $method]);
        }
    }
}
