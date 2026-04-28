<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Quote\CreateQuoteRequest;
use App\Http\Requests\Quote\ListQuotesRequest;
use App\Http\Requests\Quote\PreviewQuotePricesRequest;
use App\Http\Requests\Quote\SendQuoteRequest;
use App\Http\Requests\Quote\UpdateQuoteRequest;
use App\Http\Requests\Quote\UpdateQuoteStatusRequest;
use App\Http\Requests\Quote\UploadQuoteAttachmentRequest;
use App\Http\Resources\QuoteAttachmentResource;
use App\Http\Resources\QuoteResource;
use App\Http\Responses\ApiResponse;
use App\Services\Quote\QuoteService;
use App\Support\DomainConstants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuoteController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly QuoteService $service)
    {
    }

    public function index(ListQuotesRequest $request): JsonResponse
    {
        $items = $this->service->listQuotes(
            $request->user(),
            $request->validated(),
            (int) ($request->validated('per_page') ?? 15)
        );

        return $this->successResponse(DomainConstants::MSG_QUOTE_FETCHED, [
            'items' => QuoteResource::collection($items->items()),
            'pagination' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    public function store(CreateQuoteRequest $request): JsonResponse
    {
        $quote = $this->service->createQuote($request->user(), $request->validated(), $request);

        return $this->successResponse(DomainConstants::MSG_QUOTE_CREATED, ['quote' => new QuoteResource($quote)], 201);
    }

    public function show(Request $request, int $quoteId): JsonResponse
    {
        $quote = $this->service->getQuote($request->user(), $quoteId);

        return $this->successResponse(DomainConstants::MSG_QUOTE_FETCHED, ['quote' => new QuoteResource($quote)]);
    }

    public function update(UpdateQuoteRequest $request, int $quoteId): JsonResponse
    {
        $quote = $this->service->updateQuote($request->user(), $quoteId, $request->validated(), $request);

        return $this->successResponse(DomainConstants::MSG_QUOTE_UPDATED, ['quote' => new QuoteResource($quote)]);
    }

    public function destroy(Request $request, int $quoteId): JsonResponse
    {
        $this->service->deleteQuote($request->user(), $quoteId, $request);

        return $this->successResponse(DomainConstants::MSG_QUOTE_DELETED);
    }

    public function updateStatus(UpdateQuoteStatusRequest $request, int $quoteId): JsonResponse
    {
        $quote = $this->service->updateStatus(
            $request->user(),
            $quoteId,
            (string) $request->validated('status'),
            $request
        );

        return $this->successResponse(DomainConstants::MSG_QUOTE_STATUS_UPDATED, ['quote' => new QuoteResource($quote)]);
    }

    public function uploadAttachment(UploadQuoteAttachmentRequest $request, int $quoteId): JsonResponse
    {
        $attachment = $this->service->uploadAttachment(
            $request->user(),
            $quoteId,
            (string) $request->validated('name'),
            $request->file('file'),
            $request
        );

        return $this->successResponse(DomainConstants::MSG_QUOTE_ATTACHMENT_UPLOADED, [
            'attachment' => new QuoteAttachmentResource($attachment),
        ], 201);
    }

    public function send(SendQuoteRequest $request, int $quoteId): JsonResponse
    {
        $quote = $this->service->sendQuote($request->user(), $quoteId, $request->validated(), $request);

        return $this->successResponse(DomainConstants::MSG_QUOTE_SENT, ['quote' => new QuoteResource($quote)]);
    }

    public function layouts(): JsonResponse
    {
        return $this->successResponse(DomainConstants::MSG_QUOTE_FETCHED, [
            'items' => $this->service->listLayouts(),
        ]);
    }

    public function previewPrices(PreviewQuotePricesRequest $request): JsonResponse
    {
        $preview = $this->service->previewPrices($request->user(), $request->validated());

        return $this->successResponse(DomainConstants::MSG_QUOTE_PRICE_PREVIEWED, $preview);
    }

    public function publicShow(Request $request, string $token): JsonResponse
    {
        $quote = $this->service->getPublicQuote($token, $request);

        return $this->successResponse(DomainConstants::MSG_QUOTE_FETCHED, ['quote' => new QuoteResource($quote)]);
    }

    public function publicAccept(Request $request, string $token): JsonResponse
    {
        $quote = $this->service->acceptPublicQuote($token, $request);

        return $this->successResponse(DomainConstants::MSG_QUOTE_PUBLIC_ACCEPTED, ['quote' => new QuoteResource($quote)]);
    }

    public function publicReject(Request $request, string $token): JsonResponse
    {
        $quote = $this->service->rejectPublicQuote($token, $request);

        return $this->successResponse(DomainConstants::MSG_QUOTE_PUBLIC_REJECTED, ['quote' => new QuoteResource($quote)]);
    }
}
