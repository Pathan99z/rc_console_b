<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\DashboardRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Organization;
use App\Services\Organization\OrganizationDashboardService;
use App\Support\DomainConstants;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class OrganizationDashboardController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly OrganizationDashboardService $dashboardService) {}

    public function overview(DashboardRequest $request, int $organizationId): JsonResponse
    {
        $this->authorizeDashboard($request, $organizationId);

        return $this->successResponse(
            DomainConstants::MSG_ORGANIZATION_DASHBOARD_FETCHED,
            $this->dashboardService->overview($request->user(), $organizationId, $request->filters())
        );
    }

    public function pipeline(DashboardRequest $request, int $organizationId): JsonResponse
    {
        $this->authorizeDashboard($request, $organizationId);

        return $this->successResponse(
            DomainConstants::MSG_ORGANIZATION_DASHBOARD_FETCHED,
            $this->dashboardService->pipeline($request->user(), $organizationId, $request->filters())
        );
    }

    public function revenue(DashboardRequest $request, int $organizationId): JsonResponse
    {
        $this->authorizeDashboard($request, $organizationId);

        return $this->successResponse(
            DomainConstants::MSG_ORGANIZATION_DASHBOARD_FETCHED,
            $this->dashboardService->revenue($request->user(), $organizationId, $request->filters())
        );
    }

    public function commissions(DashboardRequest $request, int $organizationId): JsonResponse
    {
        $this->authorizeDashboard($request, $organizationId);

        return $this->successResponse(
            DomainConstants::MSG_ORGANIZATION_DASHBOARD_FETCHED,
            $this->dashboardService->commissions($request->user(), $organizationId, $request->filters())
        );
    }

    public function licenses(DashboardRequest $request, int $organizationId): JsonResponse
    {
        $this->authorizeDashboard($request, $organizationId);

        return $this->successResponse(
            DomainConstants::MSG_ORGANIZATION_DASHBOARD_FETCHED,
            $this->dashboardService->licenses($request->user(), $organizationId, $request->filters())
        );
    }

    public function activity(DashboardRequest $request, int $organizationId): JsonResponse
    {
        $this->authorizeDashboard($request, $organizationId);

        return $this->successResponse(
            DomainConstants::MSG_ORGANIZATION_DASHBOARD_FETCHED,
            $this->dashboardService->activity($request->user(), $organizationId, $request->filters())
        );
    }

    public function team(DashboardRequest $request, int $organizationId): JsonResponse
    {
        $this->authorizeDashboard($request, $organizationId);

        return $this->successResponse(
            DomainConstants::MSG_ORGANIZATION_DASHBOARD_FETCHED,
            $this->dashboardService->team($request->user(), $organizationId, $request->filters())
        );
    }

    public function resources(DashboardRequest $request, int $organizationId): JsonResponse
    {
        $this->authorizeDashboard($request, $organizationId);

        return $this->successResponse(
            DomainConstants::MSG_ORGANIZATION_DASHBOARD_FETCHED,
            $this->dashboardService->resources($request->user(), $organizationId, $request->filters())
        );
    }

    public function payouts(DashboardRequest $request, int $organizationId): JsonResponse
    {
        $this->authorizeDashboard($request, $organizationId);

        return $this->successResponse(
            DomainConstants::MSG_ORGANIZATION_DASHBOARD_FETCHED,
            $this->dashboardService->payouts($request->user(), $organizationId, $request->filters())
        );
    }

    private function authorizeDashboard(DashboardRequest $request, int $organizationId): void
    {
        $organization = Organization::query()->findOrFail($organizationId);
        Gate::forUser($request->user())->authorize('viewOrganizationDashboard', $organization);
    }
}
