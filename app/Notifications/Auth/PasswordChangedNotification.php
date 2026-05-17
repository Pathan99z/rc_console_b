<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordChangedNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appName = (string) config('app.name', 'RC Console');
        $logoUrl = rtrim((string) config('app.url'), '/').'/storage/Logo.png';

        return (new MailMessage)
            ->subject('Security Alert – Password Changed')
            ->view('emails.auth.password-changed', [
                'name' => $notifiable->name ?? 'User',
                'appName' => $appName,
                'logoUrl' => $logoUrl,
            ]);
    }
}
