<?php

namespace App\Http\Middleware;

use App\Services\Auth\PermissionResolverService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureNotificationsView
{
    public function __construct(private readonly PermissionResolverService $permissionResolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $this->permissionResolver->can($user, 'notifications.view')) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to view notifications.',
                'errors' => (object) [],
            ], 403);
        }

        return $next($request);
    }
}
