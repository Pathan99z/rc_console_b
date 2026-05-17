<?php

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
use App\Listeners\Notifications\PersistQueuedInAppNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class NotificationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $listener = PersistQueuedInAppNotification::class;
        foreach ([
            ContactAssigned::class,
            ContactReassigned::class,
            TaskAssigned::class,
            TaskReassigned::class,
            TaskCompleted::class,
            QuoteSent::class,
            QuoteAccepted::class,
            QuoteRejected::class,
            QuotePaymentSucceeded::class,
            QuotePaymentFailed::class,
            PaymentLinkInitiated::class,
            DealAssigned::class,
            DealOwnerChanged::class,
            DealStageChanged::class,
            DealWon::class,
            DealLost::class,
            PartnerOrganizationSubmittedForReview::class,
            ResellerOrganizationSubmittedForReview::class,
            PartnerApproved::class,
            PartnerRejected::class,
            PartnerSuspended::class,
            ResellerApproved::class,
            ResellerRejected::class,
            ResellerSuspended::class,
            PartnerInvitationAccepted::class,
            ResellerInvitationAccepted::class,
            LicenseAllocated::class,
            LicenseActivatedEvent::class,
            UserInvited::class,
            UserRoleChanged::class,
            UserAccessRevoked::class,
            OrganizationCreditLimitChanged::class,
        ] as $eventClass) {
            Event::listen($eventClass, [$listener, 'handle']);
        }
    }
}
