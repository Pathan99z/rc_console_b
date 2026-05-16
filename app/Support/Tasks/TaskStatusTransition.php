<?php

namespace App\Support\Tasks;

use App\Models\Task;
use Illuminate\Validation\ValidationException;

class TaskStatusTransition
{
    /**
     * @var array<string, list<string>>
     */
    private const ALLOWED = [
        Task::STATUS_PENDING => [
            Task::STATUS_IN_PROGRESS,
            Task::STATUS_CANCELLED,
        ],
        Task::STATUS_IN_PROGRESS => [
            Task::STATUS_COMPLETED,
            Task::STATUS_CANCELLED,
        ],
        Task::STATUS_COMPLETED => [
            Task::STATUS_PENDING,
        ],
        Task::STATUS_CANCELLED => [
            Task::STATUS_PENDING,
        ],
    ];

    public static function assertCanTransition(string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }

        $allowed = self::ALLOWED[$from] ?? [];
        if (! in_array($to, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot transition task from {$from} to {$to}."],
            ]);
        }
    }
}
