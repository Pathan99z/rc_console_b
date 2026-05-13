<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Auth\PermissionResolverService;
use App\Services\Prm\PrmDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class NavigationController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PermissionResolverService $permissionResolver,
        private readonly PrmDashboardService $prmDashboardService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! Gate::forUser($user)->allows('view-navigation')) {
            return $this->errorResponse('You are not allowed to access navigation.', 403);
        }
        $featureFlags = $this->permissionResolver->featureFlags($user);
        $navigationProfile = $this->permissionResolver->navigationProfile($user);

        $crmMenus = [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'route' => '/app/dashboard'],
            ['key' => 'contacts', 'label' => 'Contacts', 'route' => '/app/contacts'],
            ['key' => 'companies', 'label' => 'Companies', 'route' => '/app/companies'],
            ['key' => 'deals', 'label' => 'Deals', 'route' => '/app/deals'],
            ['key' => 'quotes', 'label' => 'Quotes', 'route' => '/app/quotes'],
            ['key' => 'products', 'label' => 'Products', 'route' => '/app/products'],
            ['key' => 'invoices', 'label' => 'Invoices', 'route' => '/app/invoices'],
            ['key' => 'payments', 'label' => 'Payments', 'route' => '/app/payments'],
        ];

        $prmMenus = ($featureFlags['prm_enabled'] ?? false)
            ? $this->prmDashboardService->navigationItems()
            : [];

        return $this->successResponse('Navigation loaded successfully.', [
            'navigation_profile' => $navigationProfile,
            'feature_flags' => $featureFlags,
            'menus' => [
                'crm' => $crmMenus,
                'prm' => $prmMenus,
            ],
        ]);
    }
}
