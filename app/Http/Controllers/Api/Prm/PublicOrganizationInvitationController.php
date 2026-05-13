<?php

namespace App\Http\Controllers\Api\Prm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Prm\AcceptOrganizationInvitationRequest;
use App\Http\Resources\Auth\UserResource;
use App\Http\Responses\ApiResponse;
use App\Services\Prm\OrganizationInvitationService;
use App\Support\DomainConstants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicOrganizationInvitationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly OrganizationInvitationService $invitationService) {}

    public function preview(Request $request): JsonResponse
    {
        $request->validate(['token' => ['required', 'string']]);
        $data = $this->invitationService->previewByPlainToken((string) $request->query('token'));

        return $this->successResponse(DomainConstants::MSG_PRM_INVITATION_PREVIEW, $data);
    }

    public function accept(AcceptOrganizationInvitationRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $result = $this->invitationService->acceptInvitation(
            (string) $validated['token'],
            (string) $validated['name'],
            (string) $validated['password'],
            (bool) $validated['terms_accepted'],
            $request->ip(),
            $request->userAgent()
        );

        return $this->successResponse(DomainConstants::MSG_PRM_INVITATION_ACCEPTED, [
            'token' => $result['token'],
            'requires_email_verification' => $result['requires_email_verification'],
            'user' => new UserResource($result['user']),
        ]);
    }
}
