<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Quote\CreateQuotePaymentLinkRequest;
use App\Http\Requests\Quote\SendStoredQuotePaymentLinkRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Payment\QuotePaymentLinkService;
use App\Support\DomainConstants;
use Illuminate\Http\JsonResponse;

class QuotePaymentLinkController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly QuotePaymentLinkService $quotePaymentLinkService)
    {
    }

    public function store(CreateQuotePaymentLinkRequest $request, int $quoteId): JsonResponse
    {
        $link = $this->quotePaymentLinkService->createStoredLink($request->user(), $quoteId, $request->validated());

        return $this->successResponse(DomainConstants::MSG_QUOTE_PAYMENT_LINK_CREATED, [
            'payment_link' => [
                'id' => $link->id,
                'token' => $link->token,
                'status' => $link->status,
                'expires_at' => $link->expires_at,
                'url' => $this->quotePaymentLinkService->publicPaymentUrl($link->token),
            ],
        ], 201);
    }

    public function send(SendStoredQuotePaymentLinkRequest $request, int $quoteId, int $linkId): JsonResponse
    {
        $link = $this->quotePaymentLinkService->sendStoredLink(
            $request->user(),
            $quoteId,
            $linkId,
            $request->validated(),
            $request
        );

        return $this->successResponse(DomainConstants::MSG_QUOTE_PAYMENT_LINK_SENT, [
            'payment_link' => [
                'id' => $link->id,
                'status' => $link->status,
                'recipient_email' => $link->recipient_email,
                'sent_at' => $link->sent_at,
                'expires_at' => $link->expires_at,
                'url' => $this->quotePaymentLinkService->publicPaymentUrl($link->token),
            ],
        ]);
    }
}
