<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\GetPaymentSettingsRequest;
use App\Http\Requests\Payment\UpsertPaymentSettingsRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Payment\PaymentSettingsService;
use App\Support\DomainConstants;
use Illuminate\Http\JsonResponse;

class PaymentSettingsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PaymentSettingsService $paymentSettingsService)
    {
    }

    public function show(GetPaymentSettingsRequest $request): JsonResponse
    {
        $tenantId = $request->user()->isGlobalAdmin()
            ? (isset($request->validated()['tenant_id']) ? (int) $request->validated('tenant_id') : null)
            : null;

        $data = $this->paymentSettingsService->getMasked($request->user(), $tenantId);

        return $this->successResponse(DomainConstants::MSG_PAYMENT_SETTINGS_FETCHED, $data);
    }

    public function store(UpsertPaymentSettingsRequest $request): JsonResponse
    {
        $data = $this->paymentSettingsService->upsert($request->user(), $request->validated());

        return $this->successResponse(DomainConstants::MSG_PAYMENT_SETTINGS_SAVED, $data, 201);
    }

    public function update(UpsertPaymentSettingsRequest $request): JsonResponse
    {
        $data = $this->paymentSettingsService->upsert($request->user(), $request->validated());

        return $this->successResponse(DomainConstants::MSG_PAYMENT_SETTINGS_SAVED, $data);
    }
}
