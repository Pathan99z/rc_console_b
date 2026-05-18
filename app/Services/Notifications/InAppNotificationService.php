<?php

namespace App\Services\Notifications;

use App\Models\InAppNotification;
use App\Models\User;
use App\Support\Cache\NotificationCountCache;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class InAppNotificationService
{
    public function __construct(private readonly NotificationCountCache $notificationCountCache) {}
    /**
     * @param  array<string, mixed>  $filters
     */
    public function listForRecipient(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        $q = InAppNotification::query()
            ->where('tenant_id', (int) $user->tenant_id)
            ->where('recipient_user_id', $user->id)
            ->orderByDesc('id');

        $read = $filters['read'] ?? null;
        if ($read === true || $read === 'true' || $read === '1') {
            $q->where('is_read', true);
        } elseif ($read === false || $read === 'false' || $read === '0') {
            $q->where('is_read', false);
        }

        $unread = $filters['unread'] ?? null;
        if (filter_var($unread, FILTER_VALIDATE_BOOLEAN)) {
            $q->where('is_read', false);
        }

        if (! empty($filters['category'])) {
            $q->where('category', (string) $filters['category']);
        }

        if (! empty($filters['notification_type'])) {
            $q->where('notification_type', (string) $filters['notification_type']);
        }

        return $q->paginate($perPage);
    }

    public function unreadCountForUser(User $user): int
    {
        return $this->notificationCountCache->remember($user, fn () => InAppNotification::query()
            ->where('tenant_id', (int) $user->tenant_id)
            ->where('recipient_user_id', $user->id)
            ->where('is_read', false)
            ->count());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(User $recipient, array $payload): InAppNotification
    {
        $payload['recipient_user_id'] = $recipient->id;
        if (! isset($payload['tenant_id'])) {
            $payload['tenant_id'] = $recipient->tenant_id;
        }

        $row = InAppNotification::query()->create($payload);
        $this->notificationCountCache->invalidate($recipient);

        return $row;
    }

    public function markRead(User $authenticatedUser, int $notificationId): ?InAppNotification
    {
        $row = InAppNotification::query()->whereKey($notificationId)->first();
        if (! $row || (int) $row->tenant_id !== (int) $authenticatedUser->tenant_id) {
            return null;
        }
        if ((int) $row->recipient_user_id !== (int) $authenticatedUser->id) {
            return null;
        }
        if (! $row->is_read) {
            $row->update([
                'is_read' => true,
                'read_at' => Carbon::now(),
            ]);
            $this->notificationCountCache->invalidate($authenticatedUser);
        }

        return $row->fresh();
    }

    public function markAllRead(User $authenticatedUser): int
    {
        $updated = InAppNotification::query()
            ->where('tenant_id', (int) $authenticatedUser->tenant_id)
            ->where('recipient_user_id', $authenticatedUser->id)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => Carbon::now(),
            ]);
        if ($updated > 0) {
            $this->notificationCountCache->invalidate($authenticatedUser);
        }

        return $updated;
    }

    /**
     * Idempotent daily reminder dedupe — same tenant + recipient + entity + notification_type + UTC day bucket.
     */
    public function hasRecentNotificationBucket(
        int $tenantId,
        int $recipientUserId,
        string $notificationType,
        ?string $entityType,
        ?int $entityId,
        string $utcDateYmd,
    ): bool {
        return InAppNotification::query()
            ->where('tenant_id', $tenantId)
            ->where('recipient_user_id', $recipientUserId)
            ->where('notification_type', $notificationType)
            ->when($entityType !== null, fn ($q) => $q->where('entity_type', $entityType))
            ->when($entityId !== null, fn ($q) => $q->where('entity_id', $entityId))
            ->whereDate('created_at', $utcDateYmd)
            ->exists();
    }
}
