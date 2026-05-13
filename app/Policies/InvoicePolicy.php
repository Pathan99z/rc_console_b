<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use App\Services\Auth\AccessScopeService;

class InvoicePolicy
{
    public function __construct(private readonly AccessScopeService $accessScopeService) {}

    public function viewAny(User $user): bool
    {
        return (bool) $user->tenant_id || $user->isGlobalAdmin();
    }

    public function view(User $user, Invoice $invoice): bool
    {
        if ($user->isGlobalAdmin()) {
            return true;
        }

        if ((int) $invoice->tenant_id !== (int) $user->tenant_id) {
            return false;
        }

        if ($user->isCompanyAdmin()) {
            return true;
        }

        $quote = $invoice->quote;
        if ($quote && (int) $quote->created_by_user_id === (int) $user->id) {
            return true;
        }

        $deal = $quote?->deal;
        if (! $deal) {
            return false;
        }

        $orgIds = $this->accessScopeService->visibleChannelOrgIds($user);

        return $orgIds !== [] && in_array((int) ($deal->partner_organization_id ?? 0), $orgIds, true);
    }
}
