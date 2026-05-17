<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\Auth\UserResource;
use App\Http\Responses\ApiResponse;
use App\Services\Auth\AccountService;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AuthService $authService,
        private readonly AccountService $accountService,
    ) {
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->authService->register($request->validated());

        return $this->successResponse(
            'Registration successful. Please verify your email.',
            ['user' => new UserResource($user)],
            201
        );
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login($request->validated(), $request);

        return $this->successResponse('Login successful.', [
            'token' => $result['token'],
            'user' => new UserResource($result['user']),
        ]);
    }

    public function verifyEmail(Request $request, int $id, string $hash): Response
    {
        try {
            $user = $this->authService->verifyEmail($request, $id, $hash);

            if ($request->expectsJson()) {
                return $this->successResponse('Email verified successfully.', [
                    'user' => new UserResource($user),
                ]);
            }

            return response()->view('auth.verification-result', [
                'title' => 'Email Verified',
                'message' => 'Your email has been verified successfully. You can now sign in to your RC Console account.',
                'frontendUrl' => env('FRONTEND_URL'),
                'logoUrl' => rtrim((string) config('app.url'), '/').'/storage/Logo.png',
                'appName' => (string) config('app.name', 'RC Console'),
            ]);
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }

            $message = data_get($exception->errors(), 'verification.0', 'Verification failed. Please request a new verification email.');

            return response()->view('auth.verification-result', [
                'title' => 'Verification Failed',
                'message' => $message,
                'frontendUrl' => env('FRONTEND_URL'),
                'logoUrl' => rtrim((string) config('app.url'), '/').'/storage/Logo.png',
                'appName' => (string) config('app.name', 'RC Console'),
            ], 400);
        }
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $request->user()->sendEmailVerificationNotification();

        return $this->successResponse('Verification email sent.');
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->authService->sendResetLink($request->validated('email'), $request);

        return $this->successResponse('Password reset link sent.');
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $this->authService->resetPassword($request->validated(), $request);

        return $this->successResponse('Password reset successful.');
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return $this->successResponse('Logout successful.');
    }

    public function user(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing(['tenant', 'roleModel', 'organizationAssignment.organization']);

        return $this->successResponse('Authenticated user fetched successfully.', [
            'user' => new UserResource($user),
        ]);
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = $this->accountService->updateProfile(
            $request->user(),
            (string) $request->validated('name'),
            $request
        );

        return $this->successResponse('Profile updated successfully.', [
            'user' => new UserResource($user),
        ]);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $this->accountService->changePassword(
            $request->user(),
            (string) $validated['current_password'],
            (string) $validated['password'],
            $request
        );

        return $this->successResponse('Password changed successfully.');
    }
}
