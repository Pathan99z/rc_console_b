<?php

namespace App\Http\Middleware;

use App\Services\Auth\PermissionResolverService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePrmResourcesManage
{
    public function __construct(private readonly PermissionResolverService $permissionResolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $this->permissionResolver->can($user, 'prm.resources.manage')) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to manage PRM resources.',
                'errors' => (object) [],
            ], 403);
        }

        return $next($request);
    }
}
