<?php

namespace App\Notifications\Auth;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends ResetPassword
{
    public function toMail(mixed $notifiable): MailMessage
    {
        $appName = (string) config('app.name', 'RC Console');
        $frontendUrl = rtrim((string) env('FRONTEND_URL', (string) config('app.url')), '/');
        $resetUrl = $frontendUrl.'/reset-password?token='.$this->token.'&email='.urlencode((string) $notifiable->email);
        $logoUrl = rtrim((string) config('app.url'), '/').'/storage/Logo.png';

        return (new MailMessage)
            ->subject('Reset your password')
            ->view('emails.auth.reset-password', [
                'name' => $notifiable->name,
                'appName' => $appName,
                'resetUrl' => $resetUrl,
                'logoUrl' => $logoUrl,
            ]);
    }
}
