<?php

use App\Models\Task;
use App\Services\Audit\AuditRetentionService;
use App\Services\Notifications\InAppNotificationDispatcher;
use App\Support\Notifications\InAppNotificationTemplateKeys;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('notifications:tasks-due-reminders', function (): void {
    $dispatcher = app(InAppNotificationDispatcher::class);
    $start = now()->startOfDay();
    $end = now()->copy()->endOfDay();

    Task::query()
        ->whereNotNull('assignee_user_id')
        ->whereNotNull('tenant_id')
        ->whereNotNull('due_at')
        ->whereNotIn('status', [Task::STATUS_COMPLETED, Task::STATUS_CANCELLED])
        ->where(function ($q) use ($start, $end): void {
            $q->where('due_at', '<', $start)
                ->orWhereBetween('due_at', [$start, $end]);
        })
        ->orderBy('id')
        ->chunkById(500, function ($tasks) use ($dispatcher, $start, $end): void {
            foreach ($tasks as $task) {
                $due = $task->due_at;
                if (! $due) {
                    continue;
                }
                if ($due->lt($start)) {
                    $dispatcher->sendScheduledTaskReminder($task, InAppNotificationTemplateKeys::TASKS_OVERDUE);
                } elseif ($due->between($start, $end)) {
                    $dispatcher->sendScheduledTaskReminder($task, InAppNotificationTemplateKeys::TASKS_DUE_TODAY);
                }
            }
        });
})->purpose('Enqueue in-app notifications for overdue and due-today tasks.');

Schedule::command('notifications:tasks-due-reminders')->dailyAt('07:10');

Artisan::command('audit:archive', function (): void {
    $n = app(AuditRetentionService::class)->archiveOlderThanSearchableWindow();
    $this->info("Archived {$n} audit row(s).");
})->purpose('Flag audit log rows past the searchable window by setting archived_at.');

Artisan::command('audit:purge {--force : Run without confirmation}', function (): void {
    if (! $this->option('force') && ! $this->confirm('Permanently delete audit rows older than the purge policy?')) {
        $this->warn('Aborted.');

        return;
    }
    $deleted = app(AuditRetentionService::class)->purgeBeyondRetention();
    $this->info("Purged {$deleted} audit row(s).");
})->purpose('Hard-delete audit log rows older than the purge retention window.');

Schedule::command('audit:archive')->dailyAt('02:20');

// Phase 2 (placeholders — wire when product requirements are finalized):
// - stale_contact_followups
// - quote_expiry_escalations
// - license_low_stock_alerts
