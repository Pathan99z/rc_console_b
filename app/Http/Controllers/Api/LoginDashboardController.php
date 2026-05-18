<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\LoginDashboardRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Dashboard\LoginDashboardService;
use App\Support\DomainConstants;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;

class LoginDashboardController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly LoginDashboardService $loginDashboardService) {}

    public function index(LoginDashboardRequest $request): JsonResponse
    {
        try {
            $payload = $this->loginDashboardService->resolve(
                $request->user(),
                $request->filters()
            );
        } catch (AuthorizationException $e) {
            return $this->errorResponse($e->getMessage() ?: DomainConstants::MSG_UNAUTHORIZED_SCOPE, null, 403);
        }

        return $this->successResponse(DomainConstants::MSG_LOGIN_DASHBOARD, $payload);
    }
}
