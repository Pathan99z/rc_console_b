<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\InAppNotificationController;
use App\Http\Controllers\Api\CollateralController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\DealController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\LoginDashboardController;
use App\Http\Controllers\Api\NavigationController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\OrganizationDashboardController;
use App\Http\Controllers\Api\OrganizationUserController;
use App\Http\Controllers\Api\PayFastWebhookController;
use App\Http\Controllers\Api\PaymentSettingsController;
use App\Http\Controllers\Api\PipelineController;
use App\Http\Controllers\Api\PipelineStageController;
use App\Http\Controllers\Api\Prm\CommissionAccrualController;
use App\Http\Controllers\Api\Prm\PartnerPayoutPortalController;
use App\Http\Controllers\Api\Prm\PayoutAccountController;
use App\Http\Controllers\Api\Prm\PayoutAdjustmentController;
use App\Http\Controllers\Api\Prm\PayoutBatchController;
use App\Http\Controllers\Api\Prm\PayoutController;
use App\Http\Controllers\Api\Prm\PayoutDisputeController;
use App\Http\Controllers\Api\Prm\ResellerPayoutPortalController;
use App\Http\Controllers\Api\Prm\LicenseEntitlementController;
use App\Http\Controllers\Api\Prm\OrganizationInvitationAdminController;
use App\Http\Controllers\Api\Prm\PartnerLeadController;
use App\Http\Controllers\Api\Prm\PartnerOpportunityController;
use App\Http\Controllers\Api\Prm\PartnerPortalShellController;
use App\Http\Controllers\Api\Prm\PartnerProgramController;
use App\Http\Controllers\Api\Prm\ResellerPortalShellController;
use App\Http\Controllers\Api\Prm\PrmResourceController;
use App\Http\Controllers\Api\Prm\PublicOrganizationInvitationController;
use App\Http\Controllers\Api\Prm\ResourceCenterController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\QuoteController;
use App\Http\Controllers\Api\QuotePaymentLinkController;
use App\Http\Controllers\Api\DemoLinkController;
use App\Http\Controllers\Api\Settings\OrganizationEmailSettingsController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\UserManagementController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:register')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register']);
});

Route::middleware('throttle:login')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
    ->middleware(['signed', 'throttle:verify-email'])
    ->name('verification.verify');

Route::get('/quotes/public/{token}', [QuoteController::class, 'publicShow']);
Route::post('/quotes/public/{token}/accept', [QuoteController::class, 'publicAccept']);
Route::post('/quotes/public/{token}/reject', [QuoteController::class, 'publicReject']);
Route::post('/quotes/public/{token}/payment-link', [QuoteController::class, 'createPublicPaymentLink'])
    ->middleware('throttle:payfast-public-link');

Route::post('/payments/webhook/payfast', [PayFastWebhookController::class, 'handle'])
    ->middleware('throttle:payfast-itn');

Route::middleware(['throttle:partner-invite-preview'])->group(function (): void {
    Route::get('/prm/invitations/preview', [PublicOrganizationInvitationController::class, 'preview']);
});

Route::middleware(['throttle:partner-invite-accept'])->group(function (): void {
    Route::post('/prm/invitations/accept', [PublicOrganizationInvitationController::class, 'accept']);
});

Route::get('/demo-links/{id}/screenshot', [DemoLinkController::class, 'screenshot'])
    ->middleware(['signed'])
    ->whereNumber('id')
    ->name('demo-links.screenshot');

Route::middleware(['auth:sanctum', 'tenant.context', 'organization.mail.context'])->group(function (): void {
    Route::post('/email/verification-notification', [AuthController::class, 'resendVerification'])
        ->middleware('throttle:verify-email')
        ->name('verification.send');
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::patch('/user/profile', [AuthController::class, 'updateProfile']);
    Route::patch('/user/password', [AuthController::class, 'changePassword'])
        ->middleware('throttle:change-password');
    Route::get('/navigation', [NavigationController::class, 'index']);
    Route::get('/dashboard', [LoginDashboardController::class, 'index']);

    Route::middleware(['audit.view', 'audit.export'])->group(function (): void {
        Route::get('/audit-logs/export', [AuditLogController::class, 'export']);
    });

    Route::middleware('audit.view')->group(function (): void {
        Route::get('/audit-logs', [AuditLogController::class, 'index']);
        Route::get('/audit-logs/{id}', [AuditLogController::class, 'show'])
            ->where('id', '^(audit|dh)-[0-9]+$');
    });

    Route::middleware('notifications.view')->group(function (): void {
        Route::get('/notifications', [InAppNotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [InAppNotificationController::class, 'unreadCount']);
        Route::patch('/notifications/read-all', [InAppNotificationController::class, 'markAllRead']);
        Route::patch('/notifications/{id}/read', [InAppNotificationController::class, 'markRead'])->whereNumber('id');
    });

    Route::middleware('tasks.view')->group(function (): void {
        Route::get('/tasks/assignable-users', [TaskController::class, 'assignableUsers']);
        Route::get('/tasks', [TaskController::class, 'index']);
        Route::get('/tasks/{id}', [TaskController::class, 'show'])->whereNumber('id');
    });

    Route::middleware('tasks.manage')->group(function (): void {
        Route::post('/tasks', [TaskController::class, 'store']);
        Route::patch('/tasks/{id}', [TaskController::class, 'update'])->whereNumber('id');
        Route::delete('/tasks/{id}', [TaskController::class, 'destroy'])->whereNumber('id');
        Route::post('/tasks/{id}/start', [TaskController::class, 'start'])->whereNumber('id');
        Route::post('/tasks/{id}/complete', [TaskController::class, 'complete'])->whereNumber('id');
        Route::post('/tasks/{id}/cancel', [TaskController::class, 'cancel'])->whereNumber('id');
        Route::post('/tasks/{id}/reopen', [TaskController::class, 'reopen'])->whereNumber('id');
    });

    Route::middleware('tasks.assign')->group(function (): void {
        Route::post('/tasks/{id}/assign', [TaskController::class, 'assign'])->whereNumber('id');
    });

    Route::middleware('demo_links.view')->group(function (): void {
        Route::get('/demo-links/shareable-organizations', [DemoLinkController::class, 'shareableOrganizations']);
        Route::get('/demo-links', [DemoLinkController::class, 'index']);
        Route::get('/demo-links/{id}', [DemoLinkController::class, 'show'])->whereNumber('id');
        Route::post('/demo-links/{id}/check-status', [DemoLinkController::class, 'checkStatus'])->whereNumber('id');
    });

    Route::middleware('demo_links.manage')->group(function (): void {
        Route::post('/demo-links', [DemoLinkController::class, 'store']);
        Route::patch('/demo-links/{id}', [DemoLinkController::class, 'update'])->whereNumber('id');
        Route::delete('/demo-links/{id}', [DemoLinkController::class, 'destroy'])->whereNumber('id');
    });

    Route::middleware('email_settings.view')->group(function (): void {
        Route::get('/settings/email', [OrganizationEmailSettingsController::class, 'show']);
        Route::get('/settings/email/providers', [OrganizationEmailSettingsController::class, 'providers']);
    });

    Route::middleware('email_settings.manage')->group(function (): void {
        Route::patch('/settings/email', [OrganizationEmailSettingsController::class, 'update']);
        Route::post('/settings/email/test', [OrganizationEmailSettingsController::class, 'test']);
    });

    Route::get('/users', [UserManagementController::class, 'index']);
    Route::post('/users', [UserManagementController::class, 'store']);
    Route::patch('/users/{userId}/status', [UserManagementController::class, 'updateStatus']);
    Route::patch('/users/{userId}/role', [UserManagementController::class, 'updateRole']);

    Route::get('/organizations', [OrganizationController::class, 'index']);
    Route::get('/organizations/parent-options', [OrganizationController::class, 'parentOptions']);
    Route::post('/organizations', [OrganizationController::class, 'store']);
    Route::get('/organizations/{organizationId}', [OrganizationController::class, 'show']);
    Route::put('/organizations/{organizationId}', [OrganizationController::class, 'update']);
    Route::patch('/organizations/{organizationId}/status', [OrganizationController::class, 'updateStatus']);
    Route::post('/organizations/{organizationId}/approve', [OrganizationController::class, 'approve']);
    Route::post('/organizations/{organizationId}/reject', [OrganizationController::class, 'reject']);
    Route::post('/organizations/{organizationId}/suspend', [OrganizationController::class, 'suspend']);

    Route::get('/organizations/{organizationId}/users', [OrganizationUserController::class, 'index']);
    Route::post('/organizations/{organizationId}/users/invite', [OrganizationUserController::class, 'invite']);
    Route::patch('/organizations/{organizationId}/users/{userId}/status', [OrganizationUserController::class, 'updateStatus']);
    Route::post('/organizations/{organizationId}/users/{userId}/reset-password', [OrganizationUserController::class, 'resetPassword']);

    Route::get('/organizations/{organizationId}/invitations', [OrganizationInvitationAdminController::class, 'index']);
    Route::post('/organizations/{organizationId}/invitations', [OrganizationInvitationAdminController::class, 'store']);
    Route::post('/organizations/{organizationId}/invitations/{invitationId}/resend', [OrganizationInvitationAdminController::class, 'resend']);
    Route::delete('/organizations/{organizationId}/invitations/{invitationId}', [OrganizationInvitationAdminController::class, 'destroy']);

    Route::get('/organizations/{organizationId}/dashboard', [OrganizationDashboardController::class, 'overview']);
    Route::get('/organizations/{organizationId}/dashboard/overview', [OrganizationDashboardController::class, 'overview']);
    Route::get('/organizations/{organizationId}/dashboard/pipeline', [OrganizationDashboardController::class, 'pipeline']);
    Route::get('/organizations/{organizationId}/dashboard/revenue', [OrganizationDashboardController::class, 'revenue']);
    Route::get('/organizations/{organizationId}/dashboard/commissions', [OrganizationDashboardController::class, 'commissions']);
    Route::get('/organizations/{organizationId}/dashboard/licenses', [OrganizationDashboardController::class, 'licenses']);
    Route::get('/organizations/{organizationId}/dashboard/activity', [OrganizationDashboardController::class, 'activity']);
    Route::get('/organizations/{organizationId}/dashboard/team', [OrganizationDashboardController::class, 'team']);
    Route::get('/organizations/{organizationId}/dashboard/resources', [OrganizationDashboardController::class, 'resources']);
    Route::get('/organizations/{organizationId}/dashboard/payouts', [OrganizationDashboardController::class, 'payouts']);

    Route::prefix('prm')->group(function (): void {
        Route::middleware('prm.programs.manage')->group(function (): void {
            Route::get('/programs', [PartnerProgramController::class, 'index']);
            Route::post('/programs', [PartnerProgramController::class, 'store']);
            Route::get('/programs/{programId}', [PartnerProgramController::class, 'show'])->whereNumber('programId');
            Route::put('/programs/{programId}', [PartnerProgramController::class, 'update'])->whereNumber('programId');
            Route::patch('/programs/{programId}/status', [PartnerProgramController::class, 'updateStatus'])->whereNumber('programId');
        });
        Route::middleware('prm.resources.manage')->group(function (): void {
            Route::get('/resources/analytics', [PrmResourceController::class, 'analytics']);
            Route::get('/resources', [PrmResourceController::class, 'index']);
            Route::post('/resources', [PrmResourceController::class, 'store']);
            Route::get('/resources/{id}', [PrmResourceController::class, 'show'])->whereNumber('id');
            Route::put('/resources/{id}', [PrmResourceController::class, 'update'])->whereNumber('id');
            Route::patch('/resources/{id}/status', [PrmResourceController::class, 'updateStatus'])->whereNumber('id');
            Route::delete('/resources/{id}', [PrmResourceController::class, 'destroy'])->whereNumber('id');
        });
        Route::post('/programs/enroll', [PartnerProgramController::class, 'enroll']);
        Route::get('/organizations/{organizationId}/program-enrollments', [PartnerProgramController::class, 'enrollments']);
        Route::get('/commission-accruals', [CommissionAccrualController::class, 'index']);
        Route::patch('/commission-accruals/{accrualId}/status', [CommissionAccrualController::class, 'updateStatus']);
        Route::get('/license-entitlements', [LicenseEntitlementController::class, 'index']);
        Route::post('/license-entitlements', [LicenseEntitlementController::class, 'store']);
        Route::post('/license-entitlements/{entitlementId}/consume', [LicenseEntitlementController::class, 'consume']);
        Route::post('/license-entitlements/transfer', [LicenseEntitlementController::class, 'transfer']);
        Route::post('/license-entitlements/{entitlementId}/activate', [LicenseEntitlementController::class, 'activate']);

        Route::middleware('prm.payouts.view')->group(function (): void {
            Route::get('/payouts', [PayoutController::class, 'index']);
            Route::get('/payouts/export', [PayoutController::class, 'export']);
            Route::get('/payout-reconciliation', [PayoutController::class, 'reconciliation']);
            Route::get('/payouts/{payoutId}/proof', [PayoutController::class, 'proof'])->whereNumber('payoutId');
            Route::get('/payouts/{payoutId}', [PayoutController::class, 'show'])->whereNumber('payoutId');
            Route::get('/payouts/{payoutId}/statement', [PayoutController::class, 'statement'])->whereNumber('payoutId');
            Route::get('/payout-adjustments', [PayoutAdjustmentController::class, 'index']);
            Route::get('/payout-disputes', [PayoutDisputeController::class, 'index']);
            Route::get('/payout-accounts', [PayoutAccountController::class, 'index']);
            Route::get('/payout-batches/{batchId}', [PayoutBatchController::class, 'show'])->whereNumber('batchId');
            Route::post('/payout-disputes', [PayoutDisputeController::class, 'store']);
        });

        Route::middleware('prm.payouts.manage')->group(function (): void {
            Route::post('/payouts/generate', [PayoutController::class, 'generate']);
            Route::post('/payouts/{payoutId}/submit', [PayoutController::class, 'submit'])->whereNumber('payoutId');
            Route::post('/payouts/{payoutId}/approve', [PayoutController::class, 'approve'])->whereNumber('payoutId');
            Route::post('/payouts/{payoutId}/reject', [PayoutController::class, 'reject'])->whereNumber('payoutId');
            Route::post('/payouts/{payoutId}/process', [PayoutController::class, 'process'])->whereNumber('payoutId');
            Route::post('/payouts/{payoutId}/mark-paid', [PayoutController::class, 'markPaid'])->whereNumber('payoutId');
            Route::post('/payouts/{payoutId}/fail', [PayoutController::class, 'fail'])->whereNumber('payoutId');
            Route::post('/payouts/{payoutId}/reverse', [PayoutController::class, 'reverse'])->whereNumber('payoutId');
            Route::post('/payout-adjustments', [PayoutAdjustmentController::class, 'store']);
            Route::post('/payout-disputes/{disputeId}/resolve', [PayoutDisputeController::class, 'resolve'])->whereNumber('disputeId');
            Route::post('/payout-disputes/{disputeId}/reject', [PayoutDisputeController::class, 'reject'])->whereNumber('disputeId');
            Route::post('/payout-accounts', [PayoutAccountController::class, 'store']);
            Route::patch('/payout-accounts/{accountId}', [PayoutAccountController::class, 'update'])->whereNumber('accountId');
            Route::post('/payout-accounts/{accountId}/verify', [PayoutAccountController::class, 'verify'])->whereNumber('accountId');
            Route::post('/payout-batches', [PayoutBatchController::class, 'store']);
            Route::post('/payout-batches/{batchId}/process', [PayoutBatchController::class, 'process'])->whereNumber('batchId');
            Route::post('/payout-batches/{batchId}/mark-paid', [PayoutBatchController::class, 'markPaid'])->whereNumber('batchId');
        });
    });

    Route::middleware(['partner.portal', 'prm.resources.view', 'organization.active'])->prefix('prm/partner')->group(function (): void {
        Route::get('/program-enrollments', [PartnerProgramController::class, 'partnerEnrollments']);
        Route::get('/navigation', [PartnerPortalShellController::class, 'navigation']);
        Route::get('/dashboard', [PartnerPortalShellController::class, 'dashboard']);
        Route::get('/leads', [PartnerLeadController::class, 'index']);
        Route::post('/leads', [PartnerLeadController::class, 'store']);
        Route::put('/leads/{leadId}', [PartnerLeadController::class, 'update']);
        Route::post('/opportunities', [PartnerOpportunityController::class, 'store']);
        Route::get('/resources/collaterals', [ResourceCenterController::class, 'index']);
        Route::post('/resources/collaterals/{collateralId}/downloads', [ResourceCenterController::class, 'recordDownload']);
        Route::middleware('prm.payouts.view')->group(function (): void {
            Route::get('/payouts', [PartnerPayoutPortalController::class, 'index']);
            Route::get('/payouts/statements', [PartnerPayoutPortalController::class, 'statements']);
            Route::get('/payouts/{payoutId}', [PartnerPayoutPortalController::class, 'show'])->whereNumber('payoutId');
        });
    });

    Route::middleware(['partner.portal', 'prm.resources.view', 'organization.active'])->prefix('prm/reseller')->group(function (): void {
        Route::get('/navigation', [ResellerPortalShellController::class, 'navigation']);
        Route::get('/dashboard', [ResellerPortalShellController::class, 'dashboard']);
        Route::middleware('prm.payouts.view')->group(function (): void {
            Route::get('/payouts', [ResellerPayoutPortalController::class, 'index']);
            Route::get('/payouts/statements', [ResellerPayoutPortalController::class, 'statements']);
            Route::get('/payouts/{payoutId}', [ResellerPayoutPortalController::class, 'show'])->whereNumber('payoutId');
        });
    });

    Route::get('/tenants', [TenantController::class, 'index']);
    Route::patch('/tenants/{tenantId}/status', [TenantController::class, 'updateStatus']);
    Route::get('/teams', [TeamController::class, 'index']);
    Route::post('/teams', [TeamController::class, 'store']);
    Route::put('/teams/{teamId}', [TeamController::class, 'update']);
    Route::delete('/teams/{teamId}', [TeamController::class, 'destroy']);

    Route::middleware('organization.active')->group(function (): void {
    Route::get('/companies', [CompanyController::class, 'index']);
    Route::post('/companies', [CompanyController::class, 'store']);
    Route::get('/companies/export', [CompanyController::class, 'export']);
    Route::post('/companies/import', [CompanyController::class, 'import']);
    Route::get('/companies/{companyId}', [CompanyController::class, 'show']);
    Route::put('/companies/{companyId}', [CompanyController::class, 'update']);
    Route::delete('/companies/{companyId}', [CompanyController::class, 'destroy']);

    Route::get('/contacts', [ContactController::class, 'index']);
    Route::post('/contacts', [ContactController::class, 'store']);
    Route::get('/contacts/export', [ContactController::class, 'export']);
    Route::post('/contacts/import', [ContactController::class, 'import']);
    Route::get('/contacts/{contactId}', [ContactController::class, 'show']);
    Route::put('/contacts/{contactId}', [ContactController::class, 'update']);
    Route::delete('/contacts/{contactId}', [ContactController::class, 'destroy']);
    Route::post('/contacts/{contactId}/activities', [ContactController::class, 'addActivity']);
    Route::post('/contacts/{contactId}/attach-company', [ContactController::class, 'attachCompany']);
    Route::post('/contacts/{contactId}/detach-company', [ContactController::class, 'detachCompany']);

    Route::get('/pipelines', [PipelineController::class, 'index']);
    Route::post('/pipelines', [PipelineController::class, 'store']);
    Route::put('/pipelines/{pipelineId}', [PipelineController::class, 'update']);
    Route::delete('/pipelines/{pipelineId}', [PipelineController::class, 'destroy']);
    Route::get('/pipelines/{pipelineId}/stages', [PipelineStageController::class, 'index']);
    Route::post('/pipelines/{pipelineId}/stages', [PipelineStageController::class, 'store']);
    Route::put('/pipelines/{pipelineId}/stages/{stageId}', [PipelineStageController::class, 'update']);
    Route::delete('/pipelines/{pipelineId}/stages/{stageId}', [PipelineStageController::class, 'destroy']);

    Route::get('/deals', [DealController::class, 'index']);
    Route::post('/deals', [DealController::class, 'store']);
    Route::get('/deals/{dealId}', [DealController::class, 'show']);
    Route::put('/deals/{dealId}', [DealController::class, 'update']);
    Route::delete('/deals/{dealId}', [DealController::class, 'destroy']);
    Route::post('/deals/{dealId}/move-stage', [DealController::class, 'moveStage']);
    Route::patch('/deals/{dealId}/status', [DealController::class, 'updateStatus']);
    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::get('/invoices/{invoiceId}', [InvoiceController::class, 'show']);

    Route::get('/products', [ProductController::class, 'index']);
    Route::post('/products', [ProductController::class, 'store']);
    Route::get('/products/{productId}', [ProductController::class, 'show']);
    Route::put('/products/{productId}', [ProductController::class, 'update']);
    Route::delete('/products/{productId}', [ProductController::class, 'destroy']);
    Route::patch('/products/{productId}/status', [ProductController::class, 'updateStatus']);

    Route::post('/collaterals', [CollateralController::class, 'store']);
    Route::get('/collaterals', [CollateralController::class, 'index']);
    Route::get('/collaterals/{collateralId}', [CollateralController::class, 'show']);
    Route::delete('/collaterals/{collateralId}', [CollateralController::class, 'destroy']);
    Route::post('/collaterals/{collateralId}/send', [CollateralController::class, 'send']);

    Route::get('/quotes', [QuoteController::class, 'index']);
    Route::get('/quote-layouts', [QuoteController::class, 'layouts']);
    Route::post('/quotes/preview-prices', [QuoteController::class, 'previewPrices']);
    Route::post('/quotes', [QuoteController::class, 'store']);
    Route::get('/quotes/{quoteId}', [QuoteController::class, 'show']);
    Route::put('/quotes/{quoteId}', [QuoteController::class, 'update']);
    Route::delete('/quotes/{quoteId}', [QuoteController::class, 'destroy']);
    Route::patch('/quotes/{quoteId}/status', [QuoteController::class, 'updateStatus']);
    Route::post('/quotes/{quoteId}/attachments', [QuoteController::class, 'uploadAttachment']);
    Route::post('/quotes/{quoteId}/send', [QuoteController::class, 'send']);
    Route::post('/quotes/{quoteId}/send-payment-link', [QuoteController::class, 'sendPaymentLink']);
    Route::post('/quotes/{quoteId}/payment-link', [QuoteController::class, 'createPaymentLink']);
    Route::post('/quotes/{quoteId}/payment-links', [QuotePaymentLinkController::class, 'store']);
    Route::post('/quotes/{quoteId}/payment-links/{linkId}/send', [QuotePaymentLinkController::class, 'send']);

    Route::get('/settings/payment', [PaymentSettingsController::class, 'show']);
    Route::post('/settings/payment', [PaymentSettingsController::class, 'store']);
    Route::put('/settings/payment', [PaymentSettingsController::class, 'update']);
    });
});
