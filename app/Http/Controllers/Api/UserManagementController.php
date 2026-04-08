<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\CreateUserRequest;
use App\Http\Requests\User\ListUsersRequest;
use App\Http\Requests\User\UpdateUserRoleRequest;
use App\Http\Requests\User\UpdateUserStatusRequest;
use App\Http\Resources\Auth\UserResource;
use App\Http\Responses\ApiResponse;
use App\Services\User\UserManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserManagementController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly UserManagementService $userManagementService)
    {
    }

    public function index(ListUsersRequest $request): JsonResponse
    {
        $users = $this->userManagementService->listUsers(
            (int) ($request->validated('per_page') ?? 15)
        );

        return $this->successResponse('Users fetched successfully.', [
            'items' => UserResource::collection($users->items()),
            'pagination' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function store(CreateUserRequest $request): JsonResponse
    {
        $user = $this->userManagementService->createUser($request->user(), $request->validated());

        return $this->successResponse('User created successfully.', [
            'user' => new UserResource($user),
        ], 201);
    }

    public function updateStatus(UpdateUserStatusRequest $request, int $userId): JsonResponse
    {
        $user = $this->userManagementService->updateUserStatus(
            $request->user(),
            $userId,
            $request->validated('status')
        );

        return $this->successResponse('User status updated successfully.', [
            'user' => new UserResource($user),
        ]);
    }

    public function updateRole(UpdateUserRoleRequest $request, int $userId): JsonResponse
    {
        $user = $this->userManagementService->updateUserRole(
            $request->user(),
            $userId,
            $request->validated('role')
        );

        return $this->successResponse('User role updated successfully.', [
            'user' => new UserResource($user),
        ]);
    }
}
