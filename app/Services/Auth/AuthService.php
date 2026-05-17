<?php

namespace App\Services\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Repositories\TenantRepository;
use App\Repositories\UserRepository;
use App\Services\Audit\BusinessAuditService;
use App\Support\Audit\BusinessAuditEventKeys;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(
        private readonly TenantRepository $tenantRepository,
        private readonly UserRepository $userRepository,
        private readonly BusinessAuditService $businessAuditService,
    ) {
    }

    public function register(array $payload): User
    {
        return DB::transaction(function () use ($payload): User {
            $tenant = $this->tenantRepository->create([
                'name' => $payload['company_name'],
                'status' => Tenant::STATUS_ACTIVE,
            ]);

            $user = $this->userRepository->create([
                'tenant_id' => $tenant->id,
                'role' => 'user',
                'status' => User::STATUS_ACTIVE,
                'name' => trim($payload['first_name'].' '.$payload['last_name']),
                'email' => strtolower($payload['email']),
                'password' => Hash::make($payload['password']),
            ]);

            if ($user instanceof MustVerifyEmail) {
                $user->sendEmailVerificationNotification();
            }

            return $user;
        });
    }

    /**
     * @return array{token: string, user: User}
     */
    public function login(array $payload, Request $request): array
    {
        $user = $this->userRepository->findByEmail(strtolower($payload['email']));

        if (! $user || ! Hash::check($payload['password'], $user->password)) {
            $this->logLoginFailure($user, 'invalid_credentials', $request);

            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        if (! $user->isActive()) {
            $this->logLoginFailure($user, 'inactive_user', $request);

            throw ValidationException::withMessages([
                'email' => ['Your account is inactive. Please contact your administrator.'],
            ]);
        }

        if ($user->tenant && $user->tenant->status !== Tenant::STATUS_ACTIVE) {
            $this->logLoginFailure($user, 'tenant_suspended', $request);

            throw ValidationException::withMessages([
                'email' => ['Your tenant is currently suspended. Please contact support.'],
            ]);
        }

        if (! $user->hasVerifiedEmail()) {
            $this->logLoginFailure($user, 'email_unverified', $request);

            throw ValidationException::withMessages([
                'email' => ['Please verify your email address before logging in.'],
            ]);
        }

        $deviceName = $payload['device_name'] ?? 'web';
        $token = $user->createToken($deviceName)->plainTextToken;

        $this->businessAuditService->record(
            BusinessAuditEventKeys::AUTH_LOGIN_SUCCESS,
            (int) $user->tenant_id,
            (int) $user->id,
            'auth',
            'login_success',
            'user',
            (int) $user->id,
            null,
            ['email' => $user->email],
            null,
            null,
            'http',
            $request->ip(),
            $request->userAgent(),
            $request
        );

        return [
            'token' => $token,
            'user' => $user,
        ];
    }

    private function logLoginFailure(?User $user, string $reasonCode, Request $request): void
    {
        $this->businessAuditService->record(
            BusinessAuditEventKeys::AUTH_LOGIN_FAILURE,
            $user?->tenant_id !== null ? (int) $user->tenant_id : null,
            null,
            'auth',
            'login_failed',
            'user',
            $user !== null ? (int) $user->id : 0,
            null,
            ['reason' => $reasonCode],
            ['email_attempt' => strtolower((string) $request->input('email'))],
            null,
            'http',
            $request->ip(),
            $request->userAgent(),
            $request
        );
    }

    public function logout(User $user): void
    {
        /** @var \Laravel\Sanctum\PersonalAccessToken|null $token */
        $token = $user->currentAccessToken();
        if ($token) {
            $token->delete();
        }
    }

    public function verifyEmail(Request $request, int $id, string $hash): User
    {
        if (! $request->hasValidSignature()) {
            throw ValidationException::withMessages([
                'verification' => ['Verification link is invalid or expired.'],
            ]);
        }

        $user = $this->userRepository->findById($id);
        if (! $user || ! hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            throw ValidationException::withMessages([
                'verification' => ['Verification link is invalid.'],
            ]);
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));

            $this->businessAuditService->record(
                BusinessAuditEventKeys::AUTH_EMAIL_VERIFIED,
                (int) $user->tenant_id,
                (int) $user->id,
                'auth',
                'email_verified',
                'user',
                (int) $user->id,
                null,
                ['email' => $user->email],
                null,
                null,
                'http',
                $request->ip(),
                $request->userAgent(),
                $request
            );
        }

        return $user;
    }

    public function sendResetLink(string $email, Request $request): void
    {
        $normalized = strtolower($email);
        $status = Password::sendResetLink(['email' => $normalized]);

        if ($status !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        $user = $this->userRepository->findByEmail($normalized);
        if ($user) {
            $this->businessAuditService->record(
                BusinessAuditEventKeys::AUTH_PASSWORD_RESET_REQUESTED,
                (int) $user->tenant_id,
                (int) $user->id,
                'auth',
                'password_reset_requested',
                'user',
                (int) $user->id,
                null,
                null,
                null,
                null,
                'http',
                $request->ip(),
                $request->userAgent(),
                $request
            );
        }
    }

    public function resetPassword(array $payload, Request $request): void
    {
        $svc = $this->businessAuditService;

        $status = Password::reset(
            [
                'email' => strtolower($payload['email']),
                'password' => $payload['password'],
                'password_confirmation' => $payload['password_confirmation'],
                'token' => $payload['token'],
            ],
            function (User $user, string $password) use ($svc, $request): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->save();

                $user->tokens()->delete();

                $svc->record(
                    BusinessAuditEventKeys::AUTH_PASSWORD_RESET_COMPLETED,
                    (int) $user->tenant_id,
                    (int) $user->id,
                    'auth',
                    'password_reset_completed',
                    'user',
                    (int) $user->id,
                    null,
                    null,
                    null,
                    null,
                    'http',
                    $request->ip(),
                    $request->userAgent(),
                    $request
                );
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }
    }
}
