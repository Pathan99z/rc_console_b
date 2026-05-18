<?php

namespace App\Support\Dashboard;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Contact;
use App\Models\ContactActivity;
use App\Models\Deal;
use App\Models\InAppNotification;
use App\Models\Invoice;
use App\Models\LicenseEntitlement;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\PaymentRecord;
use App\Models\Quote;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\PermissionResolverService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read-only aggregates for post-login role dashboards (tenant-wide and platform-wide).
 */
class LoginDashboardMetrics
{
    use DashboardChannelQuery;

    public function __construct(
        private readonly PermissionResolverService $permissionResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function tenantKpis(int $tenantId, ?Carbon $from, ?Carbon $to): array
    {
        $dealsBase = Deal::query()->where('tenant_id', $tenantId);
        $this->applyDateRange($dealsBase, $from, $to);

        $quotesBase = Quote::query()->where('tenant_id', $tenantId);
        $this->applyDateRange($quotesBase, $from, $to, 'quotes');

        $paymentsBase = PaymentRecord::query()->where('tenant_id', $tenantId);
        if ($from) {
            $paymentsBase->where('created_at', '>=', $from);
        }
        if ($to) {
            $paymentsBase->where('created_at', '<=', $to);
        }

        $revenueThisMonth = (float) PaymentRecord::query()
            ->where('tenant_id', $tenantId)
            ->where('status', PaymentRecord::STATUS_SUCCESS)
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('amount');

        $paymentsReceived = (float) (clone $paymentsBase)
            ->where('status', PaymentRecord::STATUS_SUCCESS)
            ->sum('amount');

        return [
            'total_contacts' => Contact::query()->where('tenant_id', $tenantId)->count(),
            'total_companies' => Company::query()->where('tenant_id', $tenantId)->count(),
            'open_deals' => (clone $dealsBase)->where('status', Deal::STATUS_OPEN)->count(),
            'won_deals' => (clone $dealsBase)->where('status', Deal::STATUS_WON)->count(),
            'lost_deals' => (clone $dealsBase)->where('status', Deal::STATUS_LOST)->count(),
            'quotes_sent' => (clone $quotesBase)->where('status', Quote::STATUS_SENT)->count(),
            'quotes_accepted' => (clone $quotesBase)->where('status', Quote::STATUS_ACCEPTED)->count(),
            'pending_invoices' => Invoice::query()
                ->where('tenant_id', $tenantId)
                ->where(function ($q): void {
                    $q->where('status', '!=', Invoice::STATUS_PAID)
                        ->orWhereNull('paid_at');
                })
                ->count(),
            'payments_received' => round($paymentsReceived, 2),
            'revenue_this_month' => round($revenueThisMonth, 2),
            'overdue_tasks' => $this->overdueTasksCount($tenantId, null, null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function platformKpis(?Carbon $from, ?Carbon $to): array
    {
        $paymentsBase = PaymentRecord::query();
        if ($from) {
            $paymentsBase->where('created_at', '>=', $from);
        }
        if ($to) {
            $paymentsBase->where('created_at', '<=', $to);
        }

        $partnerPending = Organization::query()
            ->where('type', Organization::TYPE_PARTNER)
            ->where('onboarding_status', Organization::ONBOARDING_PENDING_REVIEW)
            ->count();

        $resellerPending = Organization::query()
            ->where('type', Organization::TYPE_RESELLER)
            ->where('onboarding_status', Organization::ONBOARDING_PENDING_REVIEW)
            ->count();

        return [
            'total_tenants' => Tenant::query()->count(),
            'active_companies' => Organization::query()
                ->where('type', Organization::TYPE_COMPANY)
                ->where('status', Organization::STATUS_ACTIVE)
                ->count(),
            'active_partners' => Organization::query()
                ->where('type', Organization::TYPE_PARTNER)
                ->where('status', Organization::STATUS_ACTIVE)
                ->count(),
            'active_resellers' => Organization::query()
                ->where('type', Organization::TYPE_RESELLER)
                ->where('status', Organization::STATUS_ACTIVE)
                ->count(),
            'total_users' => User::query()->whereNotNull('tenant_id')->count(),
            'pending_approvals' => Organization::query()
                ->where('onboarding_status', Organization::ONBOARDING_PENDING_REVIEW)
                ->count(),
            'partner_approvals_pending' => $partnerPending,
            'reseller_approvals_pending' => $resellerPending,
            'total_transactions' => (clone $paymentsBase)->count(),
            'revenue_summary' => round((float) (clone $paymentsBase)
                ->where('status', PaymentRecord::STATUS_SUCCESS)
                ->sum('amount'), 2),
            'payment_success_count' => (int) (clone $paymentsBase)
                ->where('status', PaymentRecord::STATUS_SUCCESS)
                ->count(),
            'payment_failure_count' => (int) (clone $paymentsBase)
                ->where('status', PaymentRecord::STATUS_FAILED)
                ->count(),
            'onboarding_summary' => $this->onboardingSummary(),
            'license_alerts' => $this->licenseAlerts(),
        ];
    }

    public function assignedContactsCount(int $tenantId, int $userId, array $organizationIds): int
    {
        if ($organizationIds === []) {
            return 0;
        }

        return Contact::query()
            ->where('tenant_id', $tenantId)
            ->where('assigned_user_id', $userId)
            ->whereIn('channel_organization_id', $organizationIds)
            ->count();
    }

    /**
     * @param  list<int>|null  $organizationIds
     * @return array<string, int>
     */
    public function taskSummary(int $tenantId, ?int $userId = null, ?array $organizationIds = null): array
    {
        $base = Task::query()->where('tenant_id', $tenantId);

        if ($organizationIds !== null && $organizationIds !== []) {
            $base->where(function ($q) use ($organizationIds): void {
                $q->whereIn('scope_organization_id', $organizationIds)
                    ->orWhereNull('scope_organization_id');
            });
        }

        if ($userId !== null) {
            $base->where('assignee_user_id', $userId);
        }

        $overdue = (clone $base)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->whereNotIn('status', [Task::STATUS_COMPLETED, Task::STATUS_CANCELLED])
            ->count();

        return [
            'total' => (clone $base)->count(),
            'pending' => (clone $base)->where('status', Task::STATUS_PENDING)->count(),
            'in_progress' => (clone $base)->where('status', Task::STATUS_IN_PROGRESS)->count(),
            'completed' => (clone $base)->where('status', Task::STATUS_COMPLETED)->count(),
            'overdue' => $overdue,
        ];
    }

    /**
     * @param  list<int>  $organizationIds
     * @return array<string, int>
     */
    public function invitationStatus(int $tenantId, array $organizationIds): array
    {
        if ($organizationIds === []) {
            return [
                'pending' => 0,
                'accepted' => 0,
                'expired' => 0,
                'revoked' => 0,
            ];
        }

        $base = OrganizationInvitation::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('organization_id', $organizationIds);

        return [
            'pending' => (clone $base)->where('status', OrganizationInvitation::STATUS_PENDING)->count(),
            'accepted' => (clone $base)->where('status', OrganizationInvitation::STATUS_ACCEPTED)->count(),
            'expired' => (clone $base)->where('status', OrganizationInvitation::STATUS_EXPIRED)->count(),
            'revoked' => (clone $base)->where('status', OrganizationInvitation::STATUS_REVOKED)->count(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentNotifications(User $actor, int $limit = 5): array
    {
        if (! $this->permissionResolver->can($actor, 'notifications.view')) {
            return [];
        }

        return InAppNotification::query()
            ->where('recipient_user_id', $actor->id)
            ->when($actor->tenant_id !== null, fn ($q) => $q->where('tenant_id', $actor->tenant_id))
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'title', 'message', 'category', 'is_read', 'created_at', 'action_url'])
            ->map(fn (InAppNotification $n) => [
                'id' => $n->id,
                'title' => $n->title,
                'message' => $n->message,
                'category' => $n->category,
                'is_read' => (bool) $n->is_read,
                'action_url' => $n->action_url,
                'created_at' => $n->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentAuditEvents(?int $tenantId, int $limit = 10): array
    {
        $query = AuditLog::query()->orderByDesc('id');

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        return $query
            ->limit($limit)
            ->get(['id', 'tenant_id', 'module', 'action', 'event_key', 'entity_type', 'entity_id', 'user_id', 'created_at'])
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'tenant_id' => $log->tenant_id,
                'module' => $log->module,
                'action' => $log->action,
                'event_key' => $log->resolvedEventKey(),
                'entity_type' => $log->entity_type,
                'entity_id' => $log->entity_id,
                'user_id' => $log->user_id,
                'occurred_at' => $log->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentCrmActivityForTenant(int $tenantId, int $limit = 10): array
    {
        $events = [];

        Contact::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'first_name', 'last_name', 'email', 'created_at'])
            ->each(function (Contact $c) use (&$events): void {
                $events[] = [
                    'type' => 'contact_created',
                    'entity_type' => 'contact',
                    'entity_id' => $c->id,
                    'title' => trim($c->first_name.' '.$c->last_name) ?: $c->email,
                    'occurred_at' => $c->created_at?->toIso8601String(),
                ];
            });

        Deal::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'name', 'created_at'])
            ->each(function (Deal $d) use (&$events): void {
                $events[] = [
                    'type' => 'deal_created',
                    'entity_type' => 'deal',
                    'entity_id' => $d->id,
                    'title' => $d->name,
                    'occurred_at' => $d->created_at?->toIso8601String(),
                ];
            });

        ContactActivity::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->each(function (ContactActivity $a) use (&$events): void {
                $events[] = [
                    'type' => 'contact_activity',
                    'entity_type' => 'contact',
                    'entity_id' => $a->contact_id,
                    'title' => $a->type,
                    'occurred_at' => ($a->occurred_at ?? $a->created_at)?->toIso8601String(),
                ];
            });

        usort($events, fn (array $a, array $b) => strcmp((string) ($b['occurred_at'] ?? ''), (string) ($a['occurred_at'] ?? '')));

        return array_slice($events, 0, $limit);
    }

    /**
     * @param  list<int>|null  $organizationIds
     */
    private function overdueTasksCount(int $tenantId, ?int $userId, ?array $organizationIds): int
    {
        $base = Task::query()->where('tenant_id', $tenantId);

        if ($organizationIds !== null && $organizationIds !== []) {
            $base->where(function ($q) use ($organizationIds): void {
                $q->whereIn('scope_organization_id', $organizationIds)
                    ->orWhereNull('scope_organization_id');
            });
        }

        if ($userId !== null) {
            $base->where('assignee_user_id', $userId);
        }

        return $base
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->whereNotIn('status', [Task::STATUS_COMPLETED, Task::STATUS_CANCELLED])
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function onboardingSummary(): array
    {
        $rows = Organization::query()
            ->select('type', 'onboarding_status', DB::raw('count(*) as total'))
            ->groupBy('type', 'onboarding_status')
            ->get();

        $summary = [];
        foreach ($rows as $row) {
            $type = (string) $row->type;
            $status = (string) $row->onboarding_status;
            $summary[$type][$status] = (int) $row->total;
        }

        return $summary;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function licenseAlerts(): array
    {
        $rows = LicenseEntitlement::query()
            ->select(
                'tenant_id',
                'holder_organization_id',
                DB::raw('coalesce(sum(units_total),0) as units_total'),
                DB::raw('coalesce(sum(units_consumed),0) as units_consumed'),
            )
            ->groupBy('tenant_id', 'holder_organization_id')
            ->havingRaw('coalesce(sum(units_total),0) - coalesce(sum(units_consumed),0) <= 5')
            ->orderByRaw('coalesce(sum(units_total),0) - coalesce(sum(units_consumed),0)')
            ->limit(20)
            ->get();

        return $rows->map(function ($row): array {
            $available = max(0, (int) $row->units_total - (int) $row->units_consumed);

            return [
                'tenant_id' => (int) $row->tenant_id,
                'organization_id' => (int) $row->holder_organization_id,
                'units_available' => $available,
                'units_total' => (int) $row->units_total,
                'units_consumed' => (int) $row->units_consumed,
            ];
        })->values()->all();
    }

    private function applyDateRange($query, ?Carbon $from, ?Carbon $to, ?string $table = null): void
    {
        $col = ($table ? $table.'.' : '').'created_at';
        if ($from) {
            $query->where($col, '>=', $from);
        }
        if ($to) {
            $query->where($col, '<=', $to);
        }
    }
}
