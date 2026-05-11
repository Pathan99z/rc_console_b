<?php

namespace App\Repositories;

use App\Models\TenantPaymentSetting;

class TenantPaymentSettingRepository
{
    public function findByTenantId(int $tenantId): ?TenantPaymentSetting
    {
        return TenantPaymentSetting::query()->where('tenant_id', $tenantId)->first();
    }

    public function upsert(int $tenantId, array $payload): TenantPaymentSetting
    {
        $existing = $this->findByTenantId($tenantId);
        if ($existing) {
            $existing->update($payload);

            return $existing->refresh();
        }

        return TenantPaymentSetting::query()->create(array_merge($payload, ['tenant_id' => $tenantId]));
    }
}
