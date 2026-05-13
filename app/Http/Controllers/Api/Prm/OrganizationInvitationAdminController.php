<?php

namespace App\Http\Controllers\Api\Prm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Prm\StoreOrganizationInvitationRequest;
use App\Http\Resources\OrganizationInvitationResource;
use App\Http\Responses\ApiResponse;
use App\Services\Prm\OrganizationInvitationService;
use App\Support\DomainConstants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationInvitationAdminController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly OrganizationInvitationService $invitationService) {}

    public function index(Request $request, int $organizationId): JsonResponse
    {
        $items = $this->invitationService->listInvitations(
            $request->user(),
            $organizationId,
            (int) ($request->input('per_page', 15))
        );

        return $this->successResponse(DomainConstants::MSG_PRM_INVITATION_LISTED, [
            'items' => OrganizationInvitationResource::collection($items->items()),
            'pagination' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    public function store(StoreOrganizationInvitationRequest $request, int $organizationId): JsonResponse
    {
        $this->invitationService->createInvitation(
            $request->user(),
            $organizationId,
            (string) $request->validated('email'),
            (string) $request->validated('role_code'),
            isset($request->validated()['expires_in_days']) ? (int) $request->validated('expires_in_days') : null,
            $request->ip(),
            $request->userAgent()
        );

        return $this->successResponse(DomainConstants::MSG_PRM_INVITATION_CREATED, [], 201);
    }

    public function resend(Request $request, int $organizationId, int $invitationId): JsonResponse
    {
        $this->invitationService->resendInvitation(
            $request->user(),
            $organizationId,
            $invitationId,
            $request->ip(),
            $request->userAgent()
        );

        return $this->successResponse(DomainConstants::MSG_PRM_INVITATION_CREATED);
    }

    public function destroy(Request $request, int $organizationId, int $invitationId): JsonResponse
    {
        $this->invitationService->revokeInvitation(
            $request->user(),
            $organizationId,
            $invitationId,
            $request->ip(),
            $request->userAgent()
        );

        return $this->successResponse(DomainConstants::MSG_PRM_INVITATION_REVOKED);
    }
}
