<?php

namespace App\Http\Controllers\Api\Prm;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Prm\PrmDashboardService;
use App\Support\DomainConstants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartnerPortalShellController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PrmDashboardService $dashboardService) {}

    public function navigation(): JsonResponse
    {
        return $this->successResponse(DomainConstants::MSG_PRM_PARTNER_NAV, [
            'items' => $this->dashboardService->navigationItems(),
        ]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        return $this->successResponse(DomainConstants::MSG_PRM_PARTNER_DASHBOARD, [
            'summary' => $this->dashboardService->partnerSummary($request->user()),
        ]);
    }
}
