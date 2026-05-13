<?php

namespace App\Http\Controllers\Api\Prm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Prm\PartnerOpportunityRegisterRequest;
use App\Http\Resources\DealResource;
use App\Http\Responses\ApiResponse;
use App\Services\Prm\PartnerOpportunityService;
use App\Support\DomainConstants;
use Illuminate\Http\JsonResponse;

class PartnerOpportunityController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PartnerOpportunityService $opportunityService) {}

    public function store(PartnerOpportunityRegisterRequest $request): JsonResponse
    {
        $deal = $this->opportunityService->registerOpportunity($request->user(), $request->validated());

        return $this->successResponse(DomainConstants::MSG_PRM_OPPORTUNITY_REGISTERED, [
            'deal' => new DealResource($deal->load(['contact', 'company', 'owner', 'pipeline', 'stage'])),
        ], 201);
    }
}
