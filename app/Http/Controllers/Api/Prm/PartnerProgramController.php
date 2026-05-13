<?php

namespace App\Http\Controllers\Api\Prm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Prm\StorePartnerProgramRequest;
use App\Http\Requests\Prm\UpdatePartnerProgramRequest;
use App\Http\Requests\Prm\UpdatePartnerProgramStatusRequest;
use App\Http\Responses\ApiResponse;
use App\Models\PartnerProgram;
use App\Models\PartnerProgramEnrollment;
use App\Services\Prm\PartnerProgramService;
use App\Support\DomainConstants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartnerProgramController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PartnerProgramService $programService) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = null;
        if ($request->user()->isGlobalAdmin()) {
            $validated = $request->validate([
                'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            ]);
            $tenantId = (int) $validated['tenant_id'];
        }

        $items = $this->programService->listPrograms($request->user(), $tenantId);

        return $this->successResponse(DomainConstants::MSG_PRM_PROGRAMS_FETCHED, [
            'items' => $items->map(fn ($p) => $this->programPayload($p)),
        ]);
    }

    public function show(Request $request, int $programId): JsonResponse
    {
        $tenantIdForGlobal = $this->resolveTenantIdForGlobalAdmin($request);
        $program = $this->programService->getProgramForManage($request->user(), $programId, $tenantIdForGlobal);

        return $this->successResponse(DomainConstants::MSG_PRM_PROGRAM_FETCHED, [
            'program' => $this->programPayload($program),
        ]);
    }

    public function store(StorePartnerProgramRequest $request): JsonResponse
    {
        $program = $this->programService->createProgram(
            $request->user(),
            $request->validated(),
            $request->ip(),
            $request->userAgent()
        );

        return $this->successResponse(DomainConstants::MSG_PRM_PROGRAM_CREATED, [
            'program' => $this->programPayload($program),
        ], 201);
    }

    public function update(UpdatePartnerProgramRequest $request, int $programId): JsonResponse
    {
        $tenantIdForGlobal = $this->resolveTenantIdForGlobalAdmin($request);
        $program = $this->programService->updateProgram(
            $request->user(),
            $programId,
            $request->validated(),
            $tenantIdForGlobal,
            $request->ip(),
            $request->userAgent()
        );

        return $this->successResponse(DomainConstants::MSG_PRM_PROGRAM_UPDATED, [
            'program' => $this->programPayload($program),
        ]);
    }

    public function updateStatus(UpdatePartnerProgramStatusRequest $request, int $programId): JsonResponse
    {
        $tenantIdForGlobal = $this->resolveTenantIdForGlobalAdmin($request);
        $program = $this->programService->updateProgramStatus(
            $request->user(),
            $programId,
            (string) $request->validated('status'),
            $tenantIdForGlobal,
            $request->ip(),
            $request->userAgent()
        );

        return $this->successResponse(DomainConstants::MSG_PRM_PROGRAM_STATUS_UPDATED, [
            'program' => $this->programPayload($program),
        ]);
    }

    public function enroll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'partner_program_id' => ['required', 'integer', 'exists:partner_programs,id'],
            'tier_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $enrollment = $this->programService->enroll(
            $request->user(),
            (int) $data['organization_id'],
            (int) $data['partner_program_id'],
            isset($data['commission_percent']) ? (float) $data['commission_percent'] : null,
            $request->ip(),
            $request->userAgent()
        );

        return $this->successResponse(DomainConstants::MSG_PRM_ENROLLMENT_SAVED, [
            'enrollment' => $this->enrollmentPayload($enrollment),
        ], 201);
    }

    public function enrollments(Request $request, int $organizationId): JsonResponse
    {
        $items = $this->programService->listEnrollments($request->user(), $organizationId);

        return $this->successResponse(DomainConstants::MSG_PRM_PROGRAMS_FETCHED, [
            'items' => $items->map(fn ($e) => $this->enrollmentPayload($e)),
        ]);
    }

    public function partnerEnrollments(Request $request): JsonResponse
    {
        $items = $this->programService->listActiveEnrollmentsForPartnerPrimaryOrg($request->user());

        return $this->successResponse(DomainConstants::MSG_PRM_PARTNER_ENROLLMENTS_FETCHED, [
            'items' => $items->map(fn ($e) => $this->enrollmentPayload($e)),
        ]);
    }

    /**
     * Enrollment API shape: existing keys preserved; additive display fields for UI.
     *
     * @return array<string, mixed>
     */
    private function enrollmentPayload(PartnerProgramEnrollment $e): array
    {
        $program = $e->relationLoaded('program') ? $e->getRelation('program') : $e->program;
        $organization = $e->relationLoaded('organization') ? $e->getRelation('organization') : $e->organization;

        return [
            'id' => $e->id,
            'organization_id' => $e->organization_id,
            'partner_program_id' => $e->partner_program_id,
            'program_code' => $program?->code,
            'tier_code' => $e->tier_code,
            'commission_percent' => $e->commission_percent,
            'status' => $e->status,
            'program_name' => $program?->name,
            'program_status' => $program?->status ?? PartnerProgram::STATUS_ACTIVE,
            'organization' => $organization ? [
                'id' => $organization->id,
                'display_name' => $organization->display_name,
                'legal_name' => $organization->legal_name,
                'type' => $organization->type,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function programPayload(PartnerProgram $p): array
    {
        $status = $p->status ?? PartnerProgram::STATUS_ACTIVE;

        return [
            'id' => $p->id,
            'tenant_id' => $p->tenant_id,
            'code' => $p->code,
            'name' => $p->name,
            'description' => $p->description,
            'status' => $status,
            'tier_level' => $p->tier_level,
            'default_commission_percent' => $p->default_commission_percent,
            'rules' => $p->rules,
            'metadata' => $p->metadata,
            'is_template' => (bool) $p->is_template,
            'created_at' => $p->created_at?->toIso8601String(),
            'updated_at' => $p->updated_at?->toIso8601String(),
        ];
    }

    private function resolveTenantIdForGlobalAdmin(Request $request): ?int
    {
        if (! $request->user()->isGlobalAdmin()) {
            return null;
        }

        $validated = $request->validate([
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
        ]);

        return (int) $validated['tenant_id'];
    }
}
