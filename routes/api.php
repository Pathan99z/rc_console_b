<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\CollateralController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\DealController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\PipelineController;
use App\Http\Controllers\Api\PipelineStageController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PayFastWebhookController;
use App\Http\Controllers\Api\PaymentSettingsController;
use App\Http\Controllers\Api\QuotePaymentLinkController;
use App\Http\Controllers\Api\QuoteController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\TeamController;
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

Route::middleware(['auth:sanctum', 'tenant.context'])->group(function (): void {
    Route::post('/email/verification-notification', [AuthController::class, 'resendVerification'])
        ->middleware('throttle:verify-email')
        ->name('verification.send');
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    Route::get('/users', [UserManagementController::class, 'index']);
    Route::post('/users', [UserManagementController::class, 'store']);
    Route::patch('/users/{userId}/status', [UserManagementController::class, 'updateStatus']);
    Route::patch('/users/{userId}/role', [UserManagementController::class, 'updateRole']);

    Route::get('/tenants', [TenantController::class, 'index']);
    Route::patch('/tenants/{tenantId}/status', [TenantController::class, 'updateStatus']);
    Route::get('/teams', [TeamController::class, 'index']);
    Route::post('/teams', [TeamController::class, 'store']);
    Route::put('/teams/{teamId}', [TeamController::class, 'update']);
    Route::delete('/teams/{teamId}', [TeamController::class, 'destroy']);

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
