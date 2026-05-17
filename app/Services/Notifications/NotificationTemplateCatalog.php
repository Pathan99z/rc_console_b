<?php

namespace App\Services\Notifications;

use App\Support\Notifications\InAppNotificationTemplateKeys;

/**
 * Centralized copy and routes for in-app notifications (no DB templates in phase 1).
 */
final class NotificationTemplateCatalog
{
    /**
     * @return array{title: string, message: string, action_url: string|null, category: string, priority: string}
     */
    public static function render(string $key, array $ctx = []): array
    {
        $g = static fn (string $k, mixed $default = ''): string => trim((string) ($ctx[$k] ?? $default));

        return match ($key) {
            InAppNotificationTemplateKeys::CONTACTS_ASSIGNED => [
                'title' => 'Contact assigned to you',
                'message' => 'You have been assigned contact '.$g('contact_label').'.',
                'action_url' => '/app/contacts/'.$g('contact_id'),
                'category' => 'contacts',
                'priority' => 'normal',
            ],
            InAppNotificationTemplateKeys::CONTACTS_REASSIGNED => [
                'title' => 'New contact assignment',
                'message' => 'Contact '.$g('contact_label').' has been reassigned to you.',
                'action_url' => '/app/contacts/'.$g('contact_id'),
                'category' => 'contacts',
                'priority' => 'normal',
            ],
            InAppNotificationTemplateKeys::TASKS_ASSIGNED => [
                'title' => 'Task assigned',
                'message' => 'Task "'.$g('title').'" was assigned to you.',
                'action_url' => '/app/tasks/'.$g('task_id'),
                'category' => 'tasks',
                'priority' => 'normal',
            ],
            InAppNotificationTemplateKeys::TASKS_REASSIGNED => [
                'title' => 'Task reassigned',
                'message' => 'Task "'.$g('title').'" was reassigned to you.',
                'action_url' => '/app/tasks/'.$g('task_id'),
                'category' => 'tasks',
                'priority' => 'normal',
            ],
            InAppNotificationTemplateKeys::TASKS_COMPLETED => [
                'title' => 'Task completed',
                'message' => 'Task "'.$g('title').'" was marked completed.',
                'action_url' => '/app/tasks/'.$g('task_id'),
                'category' => 'tasks',
                'priority' => 'low',
            ],
            InAppNotificationTemplateKeys::TASKS_OVERDUE => [
                'title' => 'Overdue task',
                'message' => 'Task "'.$g('title').'" is overdue.',
                'action_url' => '/app/tasks/'.$g('task_id'),
                'category' => 'tasks',
                'priority' => 'high',
            ],
            InAppNotificationTemplateKeys::TASKS_DUE_TODAY => [
                'title' => 'Task due today',
                'message' => 'Task "'.$g('title').'" is due today.',
                'action_url' => '/app/tasks/'.$g('task_id'),
                'category' => 'tasks',
                'priority' => 'high',
            ],
            InAppNotificationTemplateKeys::QUOTES_SENT => [
                'title' => 'Quote sent',
                'message' => 'Quote '.$g('quote_number').' was sent to the customer.',
                'action_url' => '/app/quotes/'.$g('quote_id'),
                'category' => 'quotes',
                'priority' => 'normal',
            ],
            InAppNotificationTemplateKeys::QUOTES_ACCEPTED => [
                'title' => 'Quote accepted',
                'message' => 'Quote '.$g('quote_number').' was accepted by the customer.',
                'action_url' => '/app/quotes/'.$g('quote_id'),
                'category' => 'quotes',
                'priority' => 'high',
            ],
            InAppNotificationTemplateKeys::QUOTES_REJECTED => [
                'title' => 'Quote rejected',
                'message' => 'Quote '.$g('quote_number').' was rejected by the customer.',
                'action_url' => '/app/quotes/'.$g('quote_id'),
                'category' => 'quotes',
                'priority' => 'normal',
            ],
            InAppNotificationTemplateKeys::QUOTES_PAYMENT_SUCCESS => [
                'title' => 'Quote payment received',
                'message' => 'Payment for quote '.$g('quote_number').' completed successfully.',
                'action_url' => '/app/quotes/'.$g('quote_id'),
                'category' => 'quotes',
                'priority' => 'high',
            ],
            InAppNotificationTemplateKeys::QUOTES_PAYMENT_FAILED => [
                'title' => 'Quote payment failed',
                'message' => 'PayFast reported a failed payment for quote '.$g('quote_number').'.',
                'action_url' => '/app/quotes/'.$g('quote_id'),
                'category' => 'quotes',
                'priority' => 'high',
            ],
            InAppNotificationTemplateKeys::DEALS_ASSIGNED => [
                'title' => 'New deal assigned',
                'message' => 'You are the owner of deal "'.$g('deal_name').'".',
                'action_url' => '/app/deals/'.$g('deal_id'),
                'category' => 'deals',
                'priority' => 'normal',
            ],
            InAppNotificationTemplateKeys::DEALS_OWNER_CHANGED => [
                'title' => 'Deal reassigned',
                'message' => 'You are now the owner of deal "'.$g('deal_name').'".',
                'action_url' => '/app/deals/'.$g('deal_id'),
                'category' => 'deals',
                'priority' => 'normal',
            ],
            InAppNotificationTemplateKeys::DEALS_STAGE_CHANGED => [
                'title' => 'Deal stage update',
                'message' => 'Deal "'.$g('deal_name').'" moved to stage '.$g('stage_name').'.',
                'action_url' => '/app/deals/'.$g('deal_id'),
                'category' => 'deals',
                'priority' => 'normal',
            ],
            InAppNotificationTemplateKeys::DEALS_WON => [
                'title' => 'Deal won',
                'message' => 'Deal "'.$g('deal_name').'" was marked as won.',
                'action_url' => '/app/deals/'.$g('deal_id'),
                'category' => 'deals',
                'priority' => 'high',
            ],
            InAppNotificationTemplateKeys::DEALS_LOST => [
                'title' => 'Deal lost',
                'message' => 'Deal "'.$g('deal_name').'" was marked as lost.',
                'action_url' => '/app/deals/'.$g('deal_id'),
                'category' => 'deals',
                'priority' => 'normal',
            ],
            InAppNotificationTemplateKeys::PARTNERS_SUBMITTED => [
                'title' => 'Partner submitted for review',
                'message' => 'Partner "'.$g('org_name').'" is pending review.',
                'action_url' => '/app/organizations/'.$g('organization_id'),
                'category' => 'partner',
                'priority' => 'normal',
            ],
            InAppNotificationTemplateKeys::PARTNERS_APPROVED => [
                'title' => 'Partner approved',
                'message' => 'Partner "'.$g('org_name').'" has been activated.',
                'action_url' => '/app/organizations/'.$g('organization_id'),
                'category' => 'partner',
                'priority' => 'high',
            ],
            InAppNotificationTemplateKeys::PARTNERS_REJECTED => [
                'title' => 'Partner rejected',
                'message' => 'Partner "'.$g('org_name').'" registration was rejected.',
                'action_url' => '/app/organizations/'.$g('organization_id'),
                'category' => 'partner',
                'priority' => 'high',
            ],
            InAppNotificationTemplateKeys::PARTNERS_SUSPENDED => [
                'title' => 'Partner suspended',
                'message' => 'Partner "'.$g('org_name').'" has been suspended.',
                'action_url' => '/app/organizations/'.$g('organization_id'),
                'category' => 'partner',
                'priority' => 'high',
            ],
            InAppNotificationTemplateKeys::PARTNERS_INVITATION_ACCEPTED => [
                'title' => 'Partner invitation accepted',
                'message' => $g('user_name').' accepted an invitation for partner "'.$g('org_name').'".',
                'action_url' => '/app/users',
                'category' => 'partner',
                'priority' => 'normal',
            ],
            InAppNotificationTemplateKeys::RESELLERS_SUBMITTED => [
                'title' => 'Reseller submitted for review',
                'message' => 'Reseller "'.$g('org_name').'" is pending review.',
                'action_url' => '/app/organizations/'.$g('organization_id'),
                'category' => 'reseller',
                'priority' => 'normal',
            ],
            InAppNotificationTemplateKeys::RESELLERS_APPROVED => [
                'title' => 'Reseller approved',
                'message' => 'Reseller "'.$g('org_name').'" has been activated.',
                'action_url' => '/app/organizations/'.$g('organization_id'),
                'category' => 'reseller',
                'priority' => 'high',
            ],
            InAppNotificationTemplateKeys::RESELLERS_REJECTED => [
                'title' => 'Reseller rejected',
                'message' => 'Reseller "'.$g('org_name').'" registration was rejected.',
                'action_url' => '/app/organizations/'.$g('organization_id'),
                'category' => 'reseller',
                'priority' => 'high',
            ],
            InAppNotificationTemplateKeys::RESELLERS_SUSPENDED => [
                'title' => 'Reseller suspended',
                'message' => 'Reseller "'.$g('org_name').'" has been suspended.',
                'action_url' => '/app/organizations/'.$g('organization_id'),
                'category' => 'reseller',
                'priority' => 'high',
            ],
            InAppNotificationTemplateKeys::RESELLERS_INVITATION_ACCEPTED => [
                'title' => 'Reseller invitation accepted',
                'message' => $g('user_name').' accepted an invitation for reseller "'.$g('org_name').'".',
                'action_url' => '/app/users',
                'category' => 'reseller',
                'priority' => 'normal',
            ],
            InAppNotificationTemplateKeys::PAYMENTS_INITIATED => [
                'title' => 'Payment initiated',
                'message' => 'A PayFast payment was started for quote '.$g('quote_number').'.',
                'action_url' => '/app/quotes/'.$g('quote_id'),
                'category' => 'payments',
                'priority' => 'normal',
            ],
            InAppNotificationTemplateKeys::PAYMENTS_SUCCESS => [
                'title' => 'Payment successful',
                'message' => 'PayFast confirmed payment for quote '.$g('quote_number').'.',
                'action_url' => '/app/quotes/'.$g('quote_id'),
                'category' => 'payments',
                'priority' => 'high',
            ],
            InAppNotificationTemplateKeys::PAYMENTS_FAILED => [
                'title' => 'Payment failed',
                'message' => 'PayFast reported a failed payment attempt for quote '.$g('quote_number').'.',
                'action_url' => '/app/quotes/'.$g('quote_id'),
                'category' => 'payments',
                'priority' => 'high',
            ],
            InAppNotificationTemplateKeys::PAYMENTS_WALLET_CREDITED => [
                'title' => 'Organization credit updated',
                'message' => 'Credit limit updated for '.$g('org_name').'.',
                'action_url' => '/app/organizations/'.$g('organization_id'),
                'category' => 'payments',
                'priority' => 'normal',
            ],
            InAppNotificationTemplateKeys::LICENSES_ALLOCATED_PARTNER => [
                'title' => 'Licenses allocated to partner',
                'message' => $g('units').' license unit(s) allocated to '.$g('org_name').'.',
                'action_url' => '/app/prm/licenses',
                'category' => 'licenses',
                'priority' => 'normal',
            ],
            InAppNotificationTemplateKeys::LICENSES_ALLOCATED_RESELLER => [
                'title' => 'Licenses allocated to reseller',
                'message' => $g('units').' license unit(s) transferred to '.$g('org_name').'.',
                'action_url' => '/app/prm/licenses',
                'category' => 'licenses',
                'priority' => 'normal',
            ],
            InAppNotificationTemplateKeys::LICENSES_ACTIVATED => [
                'title' => 'License activation',
                'message' => $g('units').' unit(s) activated on entitlement #'.$g('entitlement_id').'.',
                'action_url' => '/app/prm/licenses',
                'category' => 'licenses',
                'priority' => 'normal',
            ],
            InAppNotificationTemplateKeys::USERS_INVITED => [
                'title' => 'Welcome to RC Console',
                'message' => 'Your '.$g('role').' account has been created.',
                'action_url' => '/app/dashboard',
                'category' => 'users',
                'priority' => 'normal',
            ],
            InAppNotificationTemplateKeys::USERS_ROLE_CHANGED => [
                'title' => 'Your role changed',
                'message' => 'Your role was updated to '.$g('role').'.',
                'action_url' => '/app/profile',
                'category' => 'users',
                'priority' => 'high',
            ],
            InAppNotificationTemplateKeys::USERS_ACCESS_REVOKED => [
                'title' => 'Account access updated',
                'message' => 'Your account status is now '.$g('status').'.',
                'action_url' => '/app/dashboard',
                'category' => 'users',
                'priority' => 'high',
            ],
            default => [
                'title' => 'Notification',
                'message' => $key,
                'action_url' => null,
                'category' => 'system',
                'priority' => 'normal',
            ],
        };
    }
}
