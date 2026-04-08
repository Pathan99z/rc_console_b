<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
                'errors' => (object) [],
            ], 401);
        }

        if (! $user->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Your account is inactive.',
                'errors' => (object) [],
            ], 403);
        }

        if (! $user->isGlobalAdmin() && $user->tenant && $user->tenant->status !== Tenant::STATUS_ACTIVE) {
            return response()->json([
                'success' => false,
                'message' => 'Your tenant is currently suspended.',
                'errors' => (object) [],
            ], 403);
        }

        $tenantHeader = $request->header('X-Tenant-ID');
        if (! $user->isGlobalAdmin() && $tenantHeader !== null && (int) $tenantHeader !== (int) $user->tenant_id) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid tenant context.',
                'errors' => (object) [],
            ], 403);
        }

        $request->attributes->set('tenant_id', $user->tenant_id);

        return $next($request);
    }
}
