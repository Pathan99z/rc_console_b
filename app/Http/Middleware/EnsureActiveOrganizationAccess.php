<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks channel users whose assigned organization is not active/approved.
 */
class EnsureActiveOrganizationAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $next($request);
        }

        if (! $user->isPartnerPortalEligible()) {
            return $next($request);
        }

        $organization = $user->organizationAssignment?->organization;
        if (! $organization) {
            return response()->json([
                'success' => false,
                'message' => 'No channel organization assignment found for this user.',
                'errors' => (object) [],
            ], 403);
        }

        if ($organization->status !== Organization::STATUS_ACTIVE) {
            return response()->json([
                'success' => false,
                'message' => 'Your organization is not active. Please contact your administrator.',
                'errors' => (object) ['onboarding_status' => [$organization->onboarding_status]],
            ], 403);
        }

        $blockedOnboarding = [
            Organization::ONBOARDING_DRAFT,
            Organization::ONBOARDING_PENDING_REVIEW,
            Organization::ONBOARDING_REJECTED,
            Organization::ONBOARDING_SUSPENDED,
        ];

        if (in_array($organization->onboarding_status, $blockedOnboarding, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Your organization onboarding is not complete.',
                'errors' => (object) ['onboarding_status' => [$organization->onboarding_status]],
            ], 403);
        }

        return $next($request);
    }
}
