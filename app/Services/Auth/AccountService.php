<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\Auth\PasswordChangedNotification;
use App\Repositories\UserRepository;
use App\Services\Audit\BusinessAuditService;
use App\Support\Audit\BusinessAuditEventKeys;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

final class AccountService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly BusinessAuditService $businessAuditService,
    ) {}

    public function assertActiveAccount(User $user): void
    {
        if (! $user->isActive()) {
            throw ValidationException::withMessages([
                'account' => ['Your account is inactive.'],
            ]);
        }
    }

    public function updateProfile(User $user, string $name, Request $request): User
    {
        $this->assertActiveAccount($user);

        $trimmed = trim($name);
        if ($trimmed === $user->name) {
            return $user->load(['tenant', 'roleModel', 'organizationAssignment.organization']);
        }

        $before = ['name' => $user->name];
        $updated = $this->userRepository->update($user, ['name' => $trimmed]);

        $this->businessAuditService->record(
            BusinessAuditEventKeys::USERS_PROFILE_UPDATED,
            $updated->tenant_id !== null ? (int) $updated->tenant_id : null,
            (int) $updated->id,
            'user',
            'profile_updated',
            'user',
            (int) $updated->id,
            $before,
            ['name' => $updated->name],
            null,
            null,
            'http',
            $request->ip(),
            $request->userAgent(),
            $request
        );

        return $updated->load(['tenant', 'roleModel', 'organizationAssignment.organization']);
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword, Request $request): void
    {
        $this->assertActiveAccount($user);

        if (! Hash::check($currentPassword, (string) $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        if (Hash::check($newPassword, (string) $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['The new password must be different from your current password.'],
            ]);
        }

        DB::transaction(function () use ($user, $newPassword, $request): void {
            $this->userRepository->update($user, [
                'password' => Hash::make($newPassword),
            ]);

            $this->revokeOtherSanctumTokens($user);

            $this->businessAuditService->record(
                BusinessAuditEventKeys::USERS_PASSWORD_CHANGED,
                $user->tenant_id !== null ? (int) $user->tenant_id : null,
                (int) $user->id,
                'user',
                'password_changed',
                'user',
                (int) $user->id,
                null,
                null,
                ['method' => 'in_app'],
                null,
                'http',
                $request->ip(),
                $request->userAgent(),
                $request
            );

            $user->notify(new PasswordChangedNotification);
        });
    }

    /**
     * Option C: keep current PAT, revoke all others.
     */
    private function revokeOtherSanctumTokens(User $user): void
    {
        $current = $user->currentAccessToken();
        if (! $current instanceof PersonalAccessToken) {
            return;
        }

        $user->tokens()->whereKeyNot($current->id)->delete();
    }

    public static function resolveLastLoginAt(User $user): ?string
    {
        $fromToken = $user->tokens()->max('last_used_at');
        if ($fromToken !== null) {
            return \Illuminate\Support\Carbon::parse($fromToken)->toIso8601String();
        }

        $fromAudit = AuditLog::query()
            ->where('user_id', $user->id)
            ->where(function ($q): void {
                $q->where('event_key', BusinessAuditEventKeys::AUTH_LOGIN_SUCCESS)
                    ->orWhere(function ($sq): void {
                        $sq->where('module', 'auth')->where('action', 'login_success');
                    });
            })
            ->orderByDesc('created_at')
            ->value('created_at');

        return $fromAudit !== null
            ? \Illuminate\Support\Carbon::parse($fromAudit)->toIso8601String()
            : null;
    }
}
