<?php

use App\Http\Middleware\EnsureActiveOrganizationAccess;
use App\Http\Middleware\EnsurePartnerPortalAccess;
use App\Http\Middleware\EnsurePrmProgramsManage;
use App\Http\Middleware\EnsurePrmResourcesManage;
use App\Http\Middleware\EnsurePrmResourcesView;
use App\Http\Middleware\EnsureTenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            \App\Http\Middleware\CorrelationIdMiddleware::class,
        ]);
        $middleware->alias([
            'tenant.context' => EnsureTenantContext::class,
            'organization.active' => EnsureActiveOrganizationAccess::class,
            'partner.portal' => EnsurePartnerPortalAccess::class,
            'prm.programs.manage' => EnsurePrmProgramsManage::class,
            'prm.resources.manage' => EnsurePrmResourcesManage::class,
            'prm.resources.view' => EnsurePrmResourcesView::class,
            'prm.payouts.manage' => \App\Http\Middleware\EnsurePrmPayoutsManage::class,
            'prm.payouts.view' => \App\Http\Middleware\EnsurePrmPayoutsView::class,
            'tasks.view' => \App\Http\Middleware\EnsureTasksView::class,
            'tasks.manage' => \App\Http\Middleware\EnsureTasksManage::class,
            'tasks.assign' => \App\Http\Middleware\EnsureTasksAssign::class,
            'demo_links.view' => \App\Http\Middleware\EnsureDemoLinksView::class,
            'demo_links.manage' => \App\Http\Middleware\EnsureDemoLinksManage::class,
            'demo_links.share' => \App\Http\Middleware\EnsureDemoLinksShare::class,
            'organization.mail.context' => \App\Http\Middleware\EnsureOrganizationMailContext::class,
            'email_settings.view' => \App\Http\Middleware\EnsureEmailSettingsView::class,
            'email_settings.manage' => \App\Http\Middleware\EnsureEmailSettingsManage::class,
            'notifications.view' => \App\Http\Middleware\EnsureNotificationsView::class,
            'audit.view' => \App\Http\Middleware\EnsureAuditView::class,
            'audit.export' => \App\Http\Middleware\EnsureAuditExport::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof ValidationException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'errors' => $e->errors(),
                ], 422);
            }

            if ($e instanceof AuthenticationException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                    'errors' => (object) [],
                ], 401);
            }

            if ($e instanceof AuthorizationException || $e instanceof AccessDeniedHttpException) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() ?: 'Forbidden.',
                    'errors' => (object) [],
                ], 403);
            }

            if ($e instanceof NotFoundHttpException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Resource not found.',
                    'errors' => (object) [],
                ], 404);
            }

            if ($e instanceof MethodNotAllowedHttpException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Method not allowed for this endpoint.',
                    'errors' => (object) [],
                ], 405);
            }

            if ($e instanceof TooManyRequestsHttpException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Too many requests. Please try again later.',
                    'errors' => (object) [],
                ], 429);
            }

            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong.',
                'errors' => (object) [],
            ], 500);
        });
    })->create();
