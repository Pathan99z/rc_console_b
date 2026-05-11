<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\CreateCompanyRequest;
use App\Http\Requests\Company\ImportCompaniesRequest;
use App\Http\Requests\Company\ListCompaniesRequest;
use App\Http\Requests\Company\UpdateCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Http\Responses\ApiResponse;
use App\Services\Contact\ContactManagementService;
use App\Support\DomainConstants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompanyController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ContactManagementService $service)
    {
    }

    public function index(ListCompaniesRequest $request): JsonResponse
    {
        $items = $this->service->listCompanies(
            $request->user(),
            $request->validated(),
            (int) ($request->validated('per_page') ?? 15)
        );

        return $this->successResponse(DomainConstants::MSG_COMPANY_FETCHED, [
            'items' => CompanyResource::collection($items->items()),
            'pagination' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    public function store(CreateCompanyRequest $request): JsonResponse
    {
        $company = $this->service->createCompany($request->user(), $request->validated());

        return $this->successResponse(DomainConstants::MSG_COMPANY_CREATED, ['company' => new CompanyResource($company)], 201);
    }

    public function show(Request $request, int $companyId): JsonResponse
    {
        $company = $this->service->getCompany($request->user(), $companyId);

        return $this->successResponse(DomainConstants::MSG_COMPANY_FETCHED, ['company' => new CompanyResource($company)]);
    }

    public function update(UpdateCompanyRequest $request, int $companyId): JsonResponse
    {
        $company = $this->service->updateCompany($request->user(), $companyId, $request->validated());

        return $this->successResponse(DomainConstants::MSG_COMPANY_UPDATED, ['company' => new CompanyResource($company)]);
    }

    public function destroy(Request $request, int $companyId): JsonResponse
    {
        $this->service->deleteCompany($request->user(), $companyId);

        return $this->successResponse(DomainConstants::MSG_COMPANY_DELETED);
    }

    public function import(ImportCompaniesRequest $request): JsonResponse
    {
        $stats = $this->service->importCompanies(
            $request->user(),
            $request->file('file'),
            $request->validated('tenant_id')
        );

        return $this->successResponse(DomainConstants::MSG_COMPANY_IMPORT_COMPLETED, $stats);
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = $this->service->exportCompanyRows($request->user(), $request->query());
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="companies-export.csv"',
        ];

        return response()->stream(function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, [
                'id',
                'name',
                'industry',
                'company_type',
                'employees',
                'revenue',
                'phone',
                'email',
                'website',
                'timezone',
                'linkedin_url',
                'address',
                'city',
                'state',
                'postal_code',
                'country',
                'description',
                'assigned_user',
                'created_by',
                'status',
            ]);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, 200, $headers);
    }
}
