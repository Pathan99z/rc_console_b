<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contact\AddContactActivityRequest;
use App\Http\Requests\Contact\CreateContactRequest;
use App\Http\Requests\Contact\ImportContactsRequest;
use App\Http\Requests\Contact\ListContactsRequest;
use App\Http\Requests\Contact\UpdateContactRequest;
use App\Http\Resources\ContactResource;
use App\Http\Responses\ApiResponse;
use App\Services\Contact\ContactManagementService;
use App\Support\DomainConstants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContactController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ContactManagementService $service)
    {
    }

    public function index(ListContactsRequest $request): JsonResponse
    {
        $items = $this->service->listContacts(
            $request->user(),
            $request->validated(),
            (int) ($request->validated('per_page') ?? 15)
        );

        return $this->successResponse(DomainConstants::MSG_CONTACT_FETCHED, [
            'items' => ContactResource::collection($items->items()),
            'pagination' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    public function store(CreateContactRequest $request): JsonResponse
    {
        $contact = $this->service->createContact($request->user(), $request->validated());

        return $this->successResponse(DomainConstants::MSG_CONTACT_CREATED, ['contact' => new ContactResource($contact)], 201);
    }

    public function show(Request $request, int $contactId): JsonResponse
    {
        $contact = $this->service->getContact($request->user(), $contactId);

        return $this->successResponse(DomainConstants::MSG_CONTACT_FETCHED, ['contact' => new ContactResource($contact)]);
    }

    public function update(UpdateContactRequest $request, int $contactId): JsonResponse
    {
        $contact = $this->service->updateContact($request->user(), $contactId, $request->validated());

        return $this->successResponse(DomainConstants::MSG_CONTACT_UPDATED, ['contact' => new ContactResource($contact)]);
    }

    public function destroy(Request $request, int $contactId): JsonResponse
    {
        $this->service->deleteContact($request->user(), $contactId);

        return $this->successResponse(DomainConstants::MSG_CONTACT_DELETED);
    }

    public function addActivity(AddContactActivityRequest $request, int $contactId): JsonResponse
    {
        $contact = $this->service->addActivity($request->user(), $contactId, $request->validated());

        return $this->successResponse(DomainConstants::MSG_ACTIVITY_ADDED, ['contact' => new ContactResource($contact)]);
    }

    public function import(ImportContactsRequest $request): JsonResponse
    {
        $stats = $this->service->importContacts(
            $request->user(),
            $request->file('file'),
            $request->validated('tenant_id')
        );

        return $this->successResponse(DomainConstants::MSG_IMPORT_COMPLETED, $stats);
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = $this->service->exportRows($request->user(), $request->query());
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="contacts-export.csv"',
        ];

        return response()->stream(function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['id', 'first_name', 'last_name', 'email', 'phone', 'lifecycle_stage', 'company_name', 'assigned_to', 'created_by']);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, 200, $headers);
    }
}
