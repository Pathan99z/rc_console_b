<?php

namespace App\Notifications\Channels;

use App\Models\User;
use App\Services\OrganizationMail\OrganizationMailResolverService;
use App\Support\OrganizationMail\OrganizationMailContext;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notification;

class OrganizationScopedMailChannel extends MailChannel
{
    /**
     * @param  mixed  $notifiable
     */
    public function send($notifiable, Notification $notification)
    {
        [$tenantId, $organizationId] = $this->resolveRoutingIds($notifiable);

        return OrganizationMailContext::run($tenantId, $organizationId, function () use ($notifiable, $notification) {
            return parent::send($notifiable, $notification);
        });
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function resolveRoutingIds(mixed $notifiable): array
    {
        if ($notifiable instanceof User) {
            $resolver = app(OrganizationMailResolverService::class);

            return [(int) $notifiable->tenant_id, $resolver->resolveDefaultOrganizationIdForUser($notifiable)];
        }

        return [null, null];
    }
}
