<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\ContactController;
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
});
