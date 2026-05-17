<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\InAppNotification;
use App\Services\Notifications\InAppNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InAppNotificationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly InAppNotificationService $notificationService) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['read', 'unread', 'category', 'notification_type']);
        $items = $this->notificationService->listForRecipient(
            $request->user(),
            $filters,
            (int) $request->input('per_page', 15),
        );

        return $this->successResponse('Notifications retrieved.', [
            'items' => collect($items->items())->map(fn (InAppNotification $n): array => $this->serialize($n))->all(),
            'pagination' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = $this->notificationService->unreadCountForUser($request->user());

        return $this->successResponse('Unread count retrieved.', [
            'count' => $count,
        ]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $row = $this->notificationService->markRead($request->user(), $id);
        if (! $row) {
            return $this->errorResponse('Notification not found.', (object) [], 404);
        }

        return $this->successResponse('Notification marked as read.', [
            'notification' => $this->serialize($row),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $updated = $this->notificationService->markAllRead($request->user());

        return $this->successResponse('All notifications marked as read.', [
            'updated' => $updated,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(InAppNotification $n): array
    {
        return [
            'id' => $n->id,
            'notification_type' => $n->notification_type,
            'category' => $n->category,
            'title' => $n->title,
            'message' => $n->message,
            'action_url' => $n->action_url,
            'entity_type' => $n->entity_type,
            'entity_id' => $n->entity_id,
            'priority' => $n->priority,
            'metadata' => $n->metadata ?? (object) [],
            'is_read' => $n->is_read,
            'read_at' => $n->read_at?->toIso8601String(),
            'organization_id' => $n->organization_id,
            'actor_user_id' => $n->actor_user_id,
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }
}
