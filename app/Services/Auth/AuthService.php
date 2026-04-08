<?php

namespace App\Services\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Repositories\TenantRepository;
use App\Repositories\UserRepository;
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

    public function login(array $payload): array
    {
        $user = $this->userRepository->findByEmail(strtolower($payload['email']));

        if (! $user || ! Hash::check($payload['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        if (! $user->isActive()) {
            throw ValidationException::withMessages([
                'email' => ['Your account is inactive. Please contact your administrator.'],
            ]);
        }

        if ($user->tenant && $user->tenant->status !== Tenant::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'email' => ['Your tenant is currently suspended. Please contact support.'],
            ]);
        }

        if (! $user->hasVerifiedEmail()) {
            throw ValidationException::withMessages([
                'email' => ['Please verify your email address before logging in.'],
            ]);
        }

        $deviceName = $payload['device_name'] ?? 'web';
        $token = $user->createToken($deviceName)->plainTextToken;

        return [
            'token' => $token,
            'user' => $user,
        ];
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
        }

        return $user;
    }

    public function sendResetLink(string $email): void
    {
        $status = Password::sendResetLink(['email' => strtolower($email)]);

        if ($status !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }
    }

    public function resetPassword(array $payload): void
    {
        $status = Password::reset(
            [
                'email' => strtolower($payload['email']),
                'password' => $payload['password'],
                'password_confirmation' => $payload['password_confirmation'],
                'token' => $payload['token'],
            ],
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->save();

                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }
    }
}
