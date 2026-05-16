<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\InviteOrganizationUserRequest;
use App\Http\Requests\Organization\ListOrganizationUsersRequest;
use App\Http\Requests\Organization\ResetOrganizationUserPasswordRequest;
use App\Http\Requests\Organization\UpdateOrganizationUserStatusRequest;
use App\Http\Resources\Auth\UserResource;
use App\Http\Responses\ApiResponse;
use App\Services\Organization\OrganizationUserManagementService;
use Illuminate\Http\JsonResponse;

class OrganizationUserController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly OrganizationUserManagementService $organizationUserManagementService) {}

    public function index(ListOrganizationUsersRequest $request, int $organizationId): JsonResponse
    {
        $users = $this->organizationUserManagementService->listUsers(
            $request->user(),
            $organizationId,
            (int) ($request->validated('per_page') ?? 15)
        );

        return $this->successResponse('Organization users fetched successfully.', [
            'items' => UserResource::collection($users->items()),
            'pagination' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function invite(InviteOrganizationUserRequest $request, int $organizationId): JsonResponse
    {
        $data = $request->validated();
        $result = $this->organizationUserManagementService->inviteUser(
            $request->user(),
            $organizationId,
            $data['email'],
            $data['role_code'],
            $data['expires_in_days'] ?? null,
            $request->ip(),
            $request->userAgent()
        );

        return $this->successResponse('Invitation created successfully.', [
            'invitation' => $result['invitation'],
            'plain_token' => $result['plain_token'],
        ], 201);
    }

    public function updateStatus(UpdateOrganizationUserStatusRequest $request, int $organizationId, int $userId): JsonResponse
    {
        $user = $this->organizationUserManagementService->updateUserStatus(
            $request->user(),
            $organizationId,
            $userId,
            $request->validated('status')
        );

        return $this->successResponse('User status updated successfully.', [
            'user' => new UserResource($user),
        ]);
    }

    public function resetPassword(ResetOrganizationUserPasswordRequest $request, int $organizationId, int $userId): JsonResponse
    {
        $this->organizationUserManagementService->resetUserPassword(
            $request->user(),
            $organizationId,
            $userId,
            $request->ip(),
            $request->userAgent()
        );

        return $this->successResponse('Password reset email sent successfully.', (object) []);
    }
}
