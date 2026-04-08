<?php

namespace App\Notifications\Auth;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

class VerifyEmailNotification extends VerifyEmail
{
    public function toMail(mixed $notifiable): MailMessage
    {
        $verificationUrl = $this->verificationUrl($notifiable);
        $appName = (string) config('app.name', 'RC Console');
        $logoUrl = rtrim((string) config('app.url'), '/').'/storage/Logo.png';

        return (new MailMessage)
            ->subject('Verify your email address')
            ->view('emails.auth.verify-email', [
                'name' => $notifiable->name,
                'verificationUrl' => $verificationUrl,
                'appName' => $appName,
                'logoUrl' => $logoUrl,
            ]);
    }

    protected function verificationUrl(mixed $notifiable): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(60),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );
    }
}
