<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Http\Responses\ApiResponse;
use App\Models\Invoice;
use App\Services\Payment\InvoiceService;
use App\Support\DomainConstants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class InvoiceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly InvoiceService $invoiceService) {}

    public function index(Request $request): JsonResponse
    {
        if (! Gate::forUser($request->user())->allows('viewAny', Invoice::class)) {
            return $this->errorResponse('Not allowed to access invoices.', 403);
        }

        $invoices = $this->invoiceService->listInvoices($request->user(), (int) ($request->query('per_page', 15)));

        return $this->successResponse(DomainConstants::MSG_INVOICE_FETCHED, [
            'items' => InvoiceResource::collection($invoices->items()),
            'pagination' => [
                'current_page' => $invoices->currentPage(),
                'per_page' => $invoices->perPage(),
                'total' => $invoices->total(),
                'last_page' => $invoices->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, int $invoiceId): JsonResponse
    {
        $invoice = $this->invoiceService->getInvoice($request->user(), $invoiceId);
        if (! Gate::forUser($request->user())->allows('view', $invoice)) {
            return $this->errorResponse('Not allowed to access this invoice.', 403);
        }

        return $this->successResponse(DomainConstants::MSG_INVOICE_FETCHED, [
            'invoice' => new InvoiceResource($invoice),
        ]);
    }
}
