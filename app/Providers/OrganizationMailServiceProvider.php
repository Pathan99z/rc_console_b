<?php

namespace App\Providers;

use App\Mail\Transport\OrganizationResolvingTransport;
use App\Notifications\Channels\OrganizationScopedMailChannel;
use App\Services\OrganizationMail\OrganizationMailResolverService;
use Illuminate\Mail\MailManager;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

class OrganizationMailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MailChannel::class, OrganizationScopedMailChannel::class);
    }

    public function boot(): void
    {
        Mail::extend('organization-aware', function (array $config): OrganizationResolvingTransport {
            return new OrganizationResolvingTransport(
                $config,
                $this->app->make(OrganizationMailResolverService::class),
                $this->app->make(MailManager::class),
            );
        });
    }
}
