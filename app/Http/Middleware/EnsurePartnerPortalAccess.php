<?php

namespace App\Http\Middleware;

use App\Services\Auth\PermissionResolverService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class EnsurePartnerPortalAccess
{
    public function __construct(private readonly PermissionResolverService $permissionResolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || (! Gate::forUser($user)->allows('access-prm-partner')
            && ! $this->permissionResolver->canAny($user, [
                'prm.partner.dashboard.view',
                'prm.leads.manage',
                'prm.opportunities.manage',
                'prm.resources.view',
            ]))) {
            return response()->json([
                'success' => false,
                'message' => 'Partner portal access only.',
                'errors' => (object) [],
            ], 403);
        }

        if (! $user->primaryOrganizationId()) {
            return response()->json([
                'success' => false,
                'message' => 'No channel organization assignment found for this user.',
                'errors' => (object) [],
            ], 403);
        }

        return $next($request);
    }
}
