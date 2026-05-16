<?php

namespace App\Http\Controllers\Api\Prm;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Prm\OrganizationPayoutAccountService;
use App\Support\DomainConstants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayoutAccountController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly OrganizationPayoutAccountService $service) {}

    public function index(Request $request): JsonResponse
    {
        $orgId = $request->query('organization_id') ? (int) $request->query('organization_id') : null;
        $items = $this->service->listForActor($request->user(), $orgId, (int) $request->input('per_page', 15));

        return $this->successResponse(DomainConstants::MSG_PRM_PAYOUT_ACCOUNT_FETCHED, [
            'items' => collect($items->items())->map(fn ($a) => $this->service->toArrayForActor($request->user(), $a)),
            'pagination' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'integer'],
            'account_holder_name' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'max:64'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'branch_name' => ['nullable', 'string', 'max:255'],
            'ifsc_code' => ['nullable', 'string', 'max:32'],
            'swift_code' => ['nullable', 'string', 'max:32'],
            'currency_code' => ['nullable', 'string', 'max:8'],
            'account_type' => ['nullable', 'string', 'max:32'],
            'is_primary' => ['nullable', 'boolean'],
        ]);

        $account = $this->service->create($request->user(), $data, $request->ip(), $request->userAgent());

        return $this->successResponse(DomainConstants::MSG_PRM_PAYOUT_ACCOUNT_CREATED, [
            'account' => $this->service->toArrayForActor($request->user(), $account, true),
        ], 201);
    }

    public function update(Request $request, int $accountId): JsonResponse
    {
        $data = $request->validate([
            'account_holder_name' => ['sometimes', 'string', 'max:255'],
            'account_number' => ['sometimes', 'string', 'max:64'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'branch_name' => ['nullable', 'string', 'max:255'],
            'ifsc_code' => ['nullable', 'string', 'max:32'],
            'swift_code' => ['nullable', 'string', 'max:32'],
            'currency_code' => ['nullable', 'string', 'max:8'],
            'account_type' => ['nullable', 'string', 'max:32'],
            'is_primary' => ['nullable', 'boolean'],
        ]);

        $account = $this->service->update($request->user(), $accountId, $data, $request->ip(), $request->userAgent());

        return $this->successResponse(DomainConstants::MSG_PRM_PAYOUT_ACCOUNT_UPDATED, [
            'account' => $this->service->toArrayForActor($request->user(), $account, true),
        ]);
    }

    public function verify(Request $request, int $accountId): JsonResponse
    {
        $account = $this->service->verify($request->user(), $accountId, $request->ip(), $request->userAgent());

        return $this->successResponse(DomainConstants::MSG_PRM_PAYOUT_ACCOUNT_VERIFIED, [
            'account' => $this->service->toArrayForActor($request->user(), $account),
        ]);
    }
}
