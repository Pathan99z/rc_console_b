<?php

use App\Http\Middleware\EnsurePartnerPortalAccess;
use App\Http\Middleware\EnsurePrmProgramsManage;
use App\Http\Middleware\EnsureTenantContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
        $middleware->alias([
            'tenant.context' => EnsureTenantContext::class,
            'partner.portal' => EnsurePartnerPortalAccess::class,
            'prm.programs.manage' => EnsurePrmProgramsManage::class,
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
