<?php

namespace App\Providers;

use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use App\Policies\InvoicePolicy;
use App\Policies\OrganizationDashboardPolicy;
use App\Services\Auth\PermissionResolverService;
use App\Support\Storage\EnterpriseStorage;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(EnterpriseStorage::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Invoice::class, InvoicePolicy::class);

        Gate::define('viewOrganizationDashboard', function (User $user, Organization $organization): bool {
            return app(OrganizationDashboardPolicy::class)->view($user, $organization);
        });

        Gate::define('access-prm-partner', function (User $user): bool {
            return app(PermissionResolverService::class)->canAny($user, [
                'prm.partner.dashboard.view',
                'prm.leads.manage',
                'prm.opportunities.manage',
                'prm.resources.view',
            ]);
        });

        Gate::define('view-navigation', function (User $user): bool {
            return app(PermissionResolverService::class)->canAny($user, [
                'contacts.view',
                'contacts.manage',
                'deals.view',
                'deals.manage',
                'prm.partner.dashboard.view',
            ]);
        });

        RateLimiter::for('login', function (Request $request): array {
            return [
                Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
            ];
        });

        RateLimiter::for('register', function (Request $request): array {
            return [
                Limit::perMinute(10)->by($request->ip()),
            ];
        });

        RateLimiter::for('verify-email', function (Request $request): array {
            return [
                Limit::perMinute(6)->by($request->ip()),
            ];
        });

        RateLimiter::for('payfast-itn', function (Request $request): array {
            return [
                Limit::perMinute(180)->by($request->ip()),
            ];
        });

        RateLimiter::for('payfast-public-link', function (Request $request): array {
            $token = (string) $request->route('token', '');

            return [
                Limit::perMinute(20)->by($request->ip().'|'.$token),
            ];
        });

        RateLimiter::for('partner-invite-preview', function (Request $request): array {
            return [Limit::perMinute(30)->by($request->ip())];
        });

        RateLimiter::for('partner-invite-accept', function (Request $request): array {
            return [Limit::perMinute(10)->by($request->ip())];
        });

        RateLimiter::for('change-password', function (Request $request): array {
            $userId = $request->user()?->id;

            return [
                Limit::perMinute(5)->by('change-password|'.($userId ?? $request->ip())),
            ];
        });
    }
}
