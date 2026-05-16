<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\DemoLink;
use App\Models\User;
use App\Services\DemoLinks\DemoLinkManagementService;
use App\Support\DemoLinks\DemoLinkAccessScope;
use App\Support\DomainConstants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class DemoLinkController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly DemoLinkManagementService $demoLinkService,
        private readonly DemoLinkAccessScope $accessScope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $items = $this->demoLinkService->listForActor(
            $request->user(),
            $request->only(['search', 'is_active', 'owner_organization_id', 'product_id']),
            (int) $request->input('per_page', 15)
        );

        return $this->successResponse(DomainConstants::MSG_DEMO_LINK_FETCHED, [
            'items' => collect($items->items())->map(fn (DemoLink $l) => $this->demoLinkItem($request->user(), $l)),
            'pagination' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $reveal = filter_var($request->query('reveal_credentials', false), FILTER_VALIDATE_BOOLEAN);
        $link = $this->demoLinkService->getForActor($request->user(), $id, $reveal);

        return $this->successResponse(DomainConstants::MSG_DEMO_LINK_FETCHED, [
            'demo_link' => $this->demoLinkItem($request->user(), $link, $reveal),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'owner_organization_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'demo_url' => ['required', 'url', 'max:2048'],
            'demo_username' => ['nullable', 'string', 'max:255'],
            'demo_password' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'check_live_status' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer'],
            'visibility' => ['nullable', 'array'],
            'visibility.*.organization_id' => ['required_with:visibility', 'integer'],
            'visibility.*.include_children' => ['nullable', 'boolean'],
            'visibility.*.visibility_type' => ['nullable', 'string', 'max:32'],
            'metadata' => ['nullable', 'array'],
            'screenshot' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
        ]);

        $link = $this->demoLinkService->create(
            $request->user(),
            $data,
            $request->file('screenshot'),
            $request->ip(),
            $request->userAgent()
        );

        return $this->successResponse(DomainConstants::MSG_DEMO_LINK_CREATED, [
            'demo_link' => $this->demoLinkItem($request->user(), $link),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'owner_organization_id' => ['sometimes', 'integer'],
            'title' => ['sometimes', 'string', 'max:255'],
            'demo_url' => ['sometimes', 'url', 'max:2048'],
            'demo_username' => ['nullable', 'string', 'max:255'],
            'demo_password' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'check_live_status' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer'],
            'visibility' => ['nullable', 'array'],
            'visibility.*.organization_id' => ['required_with:visibility', 'integer'],
            'visibility.*.include_children' => ['nullable', 'boolean'],
            'visibility.*.visibility_type' => ['nullable', 'string', 'max:32'],
            'metadata' => ['nullable', 'array'],
            'screenshot' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
        ]);

        $link = $this->demoLinkService->update(
            $request->user(),
            $id,
            $data,
            $request->file('screenshot'),
            $request->ip(),
            $request->userAgent()
        );

        return $this->successResponse(DomainConstants::MSG_DEMO_LINK_UPDATED, [
            'demo_link' => $this->demoLinkItem($request->user(), $link),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->demoLinkService->delete($request->user(), $id, $request->ip(), $request->userAgent());

        return $this->successResponse(DomainConstants::MSG_DEMO_LINK_DELETED);
    }

    public function checkStatus(Request $request, int $id): JsonResponse
    {
        $result = $this->demoLinkService->checkStatus($request->user(), $id);

        return $this->successResponse(DomainConstants::MSG_DEMO_LINK_STATUS_CHECKED, [
            'status' => $result,
        ]);
    }

    public function shareableOrganizations(Request $request): JsonResponse
    {
        return $this->successResponse(DomainConstants::MSG_DEMO_LINK_SHAREABLE_ORGS_FETCHED, [
            'organizations' => $this->demoLinkService->shareableOrganizations($request->user()),
        ]);
    }

    /**
     * Time-limited signed URL so the browser can load the image without a Bearer header.
     */
    public function screenshot(Request $request, int $id): Response
    {
        $link = DemoLink::query()->find($id);
        if (! $link || ! $link->screenshot_path) {
            abort(404);
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($link->screenshot_path)) {
            abort(404);
        }

        return $disk->response($link->screenshot_path);
    }

    /**
     * @return array<string, mixed>
     */
    private function demoLinkItem(User $actor, DemoLink $link, bool $revealCredentials = false): array
    {
        $canManage = $this->canManage($actor, $link);
        $canReveal = $this->accessScope->canRevealCredentials($actor, $link);

        return [
            'id' => $link->id,
            'title' => $link->title,
            'demo_url' => $link->demo_url,
            'demo_username' => $link->demo_username,
            'has_password' => $link->demo_password_encrypted !== null,
            'demo_password' => $revealCredentials && $canReveal
                ? $this->demoLinkService->decryptPassword($link)
                : null,
            'description' => $link->description,
            'screenshot_path' => $link->screenshot_path,
            'screenshot_url' => $this->screenshotSignedUrl($link),
            'has_screenshot' => $link->screenshot_path !== null,
            'check_live_status' => $link->check_live_status,
            'last_checked_at' => $link->last_checked_at?->toIso8601String(),
            'last_status' => $link->last_status,
            'is_active' => $link->is_active,
            'owner_organization_id' => $link->owner_organization_id,
            'created_by_user_id' => $link->created_by_user_id,
            'owner_organization' => $link->ownerOrganization ? [
                'id' => $link->ownerOrganization->id,
                'type' => $link->ownerOrganization->type,
                'display_name' => $link->ownerOrganization->display_name,
            ] : null,
            'creator' => $link->creator ? [
                'id' => $link->creator->id,
                'name' => $link->creator->name,
                'email' => $link->creator->email,
            ] : null,
            'products' => $link->products->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
            ])->values()->all(),
            'visibility' => $link->visibilities->map(fn ($v) => [
                'organization_id' => $v->organization_id,
                'include_children' => $v->include_children,
                'visibility_type' => $v->visibility_type,
                'organization' => $v->organization ? [
                    'id' => $v->organization->id,
                    'display_name' => $v->organization->display_name,
                    'type' => $v->organization->type,
                ] : null,
            ])->values()->all(),
            'permissions' => [
                'can_view' => true,
                'can_edit' => $canManage,
                'can_reveal_credentials' => $canReveal,
                'can_check_status' => true,
                'can_open' => true,
            ],
            'metadata' => $link->metadata,
            'created_at' => $link->created_at?->toIso8601String(),
            'updated_at' => $link->updated_at?->toIso8601String(),
        ];
    }

    private function canManage(User $actor, DemoLink $link): bool
    {
        try {
            $this->accessScope->assertCanManageDemoLink($actor, $link);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function screenshotSignedUrl(DemoLink $link): ?string
    {
        if ($link->screenshot_path === null || $link->screenshot_path === '') {
            return null;
        }

        return URL::temporarySignedRoute(
            'demo-links.screenshot',
            now()->addMinutes(120),
            ['id' => $link->id],
            absolute: true,
        );
    }
}
