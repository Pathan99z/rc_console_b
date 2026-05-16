<?php

namespace App\Http\Middleware;

use App\Services\OrganizationMail\OrganizationMailResolverService;
use App\Support\OrganizationMail\OrganizationMailContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOrganizationMailContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $resolver = app(OrganizationMailResolverService::class);
        OrganizationMailContext::push((int) $user->tenant_id, $resolver->resolveDefaultOrganizationIdForUser($user));

        try {
            return $next($request);
        } finally {
            OrganizationMailContext::pop();
        }
    }
}
