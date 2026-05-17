<?php

namespace App\Listeners\Notifications;

use App\Services\Notifications\InAppNotificationDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class PersistQueuedInAppNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $afterCommit = true;

    /** @var int */
    public $tries = 3;

    public function __construct(private readonly InAppNotificationDispatcher $dispatcher) {}

    public function handle(object $event): void
    {
        $this->dispatcher->dispatch($event);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 120];
    }
}
