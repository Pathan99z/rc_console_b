<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\OrganizationMail\OrganizationEmailProviderPresets;
use App\Services\OrganizationMail\OrganizationEmailSettingsService;
use App\Services\OrganizationMail\OrganizationEmailTestService;
use App\Services\OrganizationMail\OrganizationMailResolverService;
use App\Support\DomainConstants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OrganizationEmailSettingsController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly OrganizationEmailSettingsService $settingsService,
        private readonly OrganizationEmailTestService $testService,
        private readonly OrganizationMailResolverService $mailResolver,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $organizationId = $this->resolveTargetOrganizationId($request);

        return $this->successResponse(DomainConstants::MSG_EMAIL_SETTINGS_FETCHED, [
            'email' => $this->settingsService->getDetail($request->user(), $organizationId),
        ]);
    }

    public function providers(Request $request): JsonResponse
    {
        return $this->successResponse(DomainConstants::MSG_EMAIL_SETTINGS_PROVIDERS_FETCHED, [
            'providers' => OrganizationEmailProviderPresets::providersForApi(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'integer'],
            'provider' => ['nullable', 'string', 'max:64'],
            'driver' => ['nullable', 'string', 'max:32'],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:500'],
            'from_address' => ['nullable', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'reply_to' => ['nullable', 'email', 'max:255'],
            'encryption' => ['nullable', 'string', 'max:16'],
            'is_active' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ]);

        $row = $this->settingsService->upsert($request->user(), $data, $request->ip(), $request->userAgent());

        return $this->successResponse(DomainConstants::MSG_EMAIL_SETTINGS_UPDATED, [
            'settings' => [
                'organization_id' => $row->organization_id,
                'provider' => $row->provider,
                'driver' => $row->driver,
                'host' => $row->host,
                'port' => $row->port,
                'username' => $row->username,
                'has_password' => $row->encrypted_password !== null && $row->encrypted_password !== '',
                'from_address' => $row->from_address,
                'from_name' => $row->from_name,
                'reply_to' => $row->reply_to,
                'encryption' => $row->encryption,
                'is_active' => $row->is_active,
                'is_verified' => $row->is_verified,
                'last_tested_at' => $row->last_tested_at?->toIso8601String(),
                'failure_count' => $row->failure_count,
                'metadata' => $row->metadata,
            ],
        ]);
    }

    public function test(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'integer'],
            'recipient_email' => ['required', 'email'],
        ]);

        $result = $this->testService->sendTest(
            $request->user(),
            (int) $data['organization_id'],
            (string) $data['recipient_email'],
            $request->ip(),
            $request->userAgent()
        );

        $status = ($result['success'] ?? false) ? 200 : 422;

        return response()->json([
            'success' => (bool) ($result['success'] ?? false),
            'message' => (string) ($result['message'] ?? ''),
            'data' => $result,
        ], $status);
    }

    private function resolveTargetOrganizationId(Request $request): int
    {
        $queryOrg = $request->query('organization_id');
        if ($queryOrg !== null && $queryOrg !== '') {
            return (int) $queryOrg;
        }

        $resolved = $this->mailResolver->resolveDefaultOrganizationIdForUser($request->user());
        if ($resolved === null || $resolved <= 0) {
            throw ValidationException::withMessages([
                'organization_id' => ['Unable to resolve organization context for email settings. Pass organization_id explicitly.'],
            ]);
        }

        return $resolved;
    }
}
