<?php

declare(strict_types=1);

namespace App\Support\Mail;

use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

/**
 * Queues outbound mail when enterprise cache mail queue is enabled (sync in tests).
 */
final class EnterpriseMailDispatcher
{
    public function send(string $recipientEmail, Mailable $mailable): void
    {
        if (config('enterprise_cache.mail_queue', true) && config('queue.default') !== 'sync') {
            Mail::to($recipientEmail)->queue($mailable);

            return;
        }

        Mail::to($recipientEmail)->send($mailable);
    }
}
