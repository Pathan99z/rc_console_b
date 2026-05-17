<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Auth\PermissionResolverService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAuditView
{
    public function __construct(private readonly PermissionResolverService $permissionResolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! ($user->isGlobalAdmin() || $user->isCompanyAdmin())
            || ! $this->permissionResolver->can($user, 'audit.view')) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to view audit logs.',
                'errors' => (object) [],
            ], 403);
        }

        return $next($request);
    }
}
