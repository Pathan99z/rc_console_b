<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\IndexAuditLogsRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Audit\AuditLogExportService;
use App\Services\Audit\AuditLogQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AuditLogController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AuditLogQueryService $auditLogQueryService,
        private readonly AuditLogExportService $auditLogExportService,
    ) {}

    public function index(IndexAuditLogsRequest $request): JsonResponse
    {
        $user = $request->user();
        $filters = $request->validated();
        $perPage = (int) ($filters['per_page'] ?? 15);

        $paginator = $this->auditLogQueryService->paginateUnified($user, $filters, $perPage);

        return $this->successResponse('Audit logs retrieved.', [
            'items' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $row = $this->auditLogQueryService->findUnified($user, $id);
        if (! $row) {
            return response()->json([
                'success' => false,
                'message' => 'Resource not found.',
                'errors' => (object) [],
            ], 404);
        }

        return $this->successResponse('Audit log entry retrieved.', [
            'entry' => $row,
        ]);
    }

    public function export(IndexAuditLogsRequest $request): StreamedResponse
    {
        $user = $request->user();
        $filters = $request->validated();

        return $this->auditLogExportService->streamCsv($user, $filters);
    }
}
