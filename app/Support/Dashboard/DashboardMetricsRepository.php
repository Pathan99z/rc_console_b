<?php

namespace App\Support\Dashboard;

use App\Models\CollateralDownload;
use App\Models\CommissionAccrual;
use App\Models\Company;
use App\Models\Contact;
use App\Models\ContactActivity;
use App\Models\Deal;
use App\Models\DealHistory;
use App\Models\LicenseActivation;
use App\Models\LicenseEntitlement;
use App\Models\LicenseMovement;
use App\Models\Organization;
use App\Models\PartnerLead;
use App\Models\PaymentRecord;
use App\Models\Payout;
use App\Models\PipelineStage;
use App\Models\Quote;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only aggregate queries for organization dashboards.
 */

class DashboardMetricsRepository
{
    use DashboardChannelQuery;

    /**
     * @return array<string, mixed>
     */
    public function overview(DashboardScope $scope, ?Carbon $from, ?Carbon $to): array
    {
        $tenantId = $scope->tenantId;
        $orgIds = $scope->organizationIds;

        $dealsBase = Deal::query();
        $this->whereDealChannelScope($dealsBase, $tenantId, $orgIds);
        $this->applyDateRange($dealsBase, $from, $to);

        $openDeals = (clone $dealsBase)->where('status', Deal::STATUS_OPEN)->count();
        $wonDeals = (clone $dealsBase)->where('status', Deal::STATUS_WON)->count();
        $lostDeals = (clone $dealsBase)->where('status', Deal::STATUS_LOST)->count();
        $totalDeals = (clone $dealsBase)->count();
        $closedDeals = $wonDeals + $lostDeals;
        $dealConversionPct = $closedDeals > 0 ? round(($wonDeals / $closedDeals) * 100, 2) : 0.0;

        $contactsQ = Contact::query();
        $this->whereChannelScope($contactsQ, $tenantId, $orgIds, 'channel_organization_id');
        $this->applyDateRange($contactsQ, $from, $to);

        $companiesQ = Company::query();
        $this->whereChannelScope($companiesQ, $tenantId, $orgIds, 'channel_organization_id');
        $this->applyDateRange($companiesQ, $from, $to);

        $quotesQ = Quote::query();
        $this->whereChannelScope($quotesQ, $tenantId, $orgIds, 'channel_organization_id', null, 'quotes');
        $this->applyDateRange($quotesQ, $from, $to, 'quotes');

        $totalQuotes = (clone $quotesQ)->count();
        $sentQuotes = (clone $quotesQ)->where('status', Quote::STATUS_SENT)->count();
        $acceptedQuotes = (clone $quotesQ)->whereIn('status', [Quote::STATUS_ACCEPTED])->count();
        $paidQuotes = (clone $quotesQ)->where('payment_status', Quote::PAYMENT_STATUS_PAID)->count();
        $quoteConversionPct = $sentQuotes > 0
            ? round((max($acceptedQuotes, $paidQuotes) / $sentQuotes) * 100, 2)
            : 0.0;

        $revenue = $this->paymentTotals($scope, $from, $to);
        $avgDealValue = $totalDeals > 0
            ? round((float) (clone $dealsBase)->avg('estimated_value'), 2)
            : 0.0;

        $commissions = $this->commissionTotals($scope, $from, $to);
        $payouts = $this->payoutTotals($scope, $from, $to);
        $licenses = $this->licenseTotals($scope);
        $users = $this->userTotals($scope);
        $resources = $this->resourceTotals($scope, $from, $to);

        return [
            'crm' => [
                'contacts' => (clone $contactsQ)->count(),
                'companies' => (clone $companiesQ)->count(),
                'deals' => $totalDeals,
                'quotes' => $totalQuotes,
            ],
            'deals' => [
                'open' => $openDeals,
                'won' => $wonDeals,
                'lost' => $lostDeals,
                'pending' => $openDeals,
                'conversion_percent' => $dealConversionPct,
                'average_value' => $avgDealValue,
                'pipeline_value' => round((float) (clone $dealsBase)->where('status', Deal::STATUS_OPEN)->sum('estimated_value'), 2),
            ],
            'revenue' => array_merge($revenue, [
                'quote_conversion_percent' => $quoteConversionPct,
            ]),
            'commissions' => $commissions,
            'payouts' => $payouts,
            'licenses' => $licenses,
            'users' => $users,
            'resources' => $resources,
            'leads' => PartnerLead::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('partner_organization_id', $orgIds)
                ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
                ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
                ->count(),
            'last_activity_at' => $this->lastActivityAt($scope),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function pipeline(DashboardScope $scope, ?Carbon $from, ?Carbon $to): array
    {
        $tenantId = $scope->tenantId;
        $orgIds = $scope->organizationIds;

        $dealsBase = Deal::query();
        $this->whereDealChannelScope($dealsBase, $tenantId, $orgIds);
        $this->applyDateRange($dealsBase, $from, $to);

        $byStage = (clone $dealsBase)
            ->select('pipeline_stage_id', DB::raw('count(*) as deal_count'), DB::raw('coalesce(sum(estimated_value),0) as stage_value'))
            ->groupBy('pipeline_stage_id')
            ->get()
            ->map(function ($row): array {
                $stage = PipelineStage::query()->find($row->pipeline_stage_id);

                return [
                    'pipeline_stage_id' => (int) $row->pipeline_stage_id,
                    'stage_name' => $stage?->name,
                    'deal_count' => (int) $row->deal_count,
                    'stage_value' => (float) $row->stage_value,
                ];
            })
            ->values()
            ->all();

        $openValue = round((float) (clone $dealsBase)->where('status', Deal::STATUS_OPEN)->sum('estimated_value'), 2);
        $wonValue = round((float) (clone $dealsBase)->where('status', Deal::STATUS_WON)->sum('estimated_value'), 2);
        $lostValue = round((float) (clone $dealsBase)->where('status', Deal::STATUS_LOST)->sum('estimated_value'), 2);

        $won = (clone $dealsBase)->where('status', Deal::STATUS_WON)->count();
        $lost = (clone $dealsBase)->where('status', Deal::STATUS_LOST)->count();
        $totalClosed = $won + $lost;

        $quotesQ = Quote::query();
        $this->whereChannelScope($quotesQ, $tenantId, $orgIds, 'channel_organization_id', null, 'quotes');
        $this->applyDateRange($quotesQ, $from, $to, 'quotes');
        $quotesWon = (clone $quotesQ)->whereIn('status', [Quote::STATUS_ACCEPTED])->count();

        return [
            'stages' => $byStage,
            'values' => [
                'open' => $openValue,
                'won' => $wonValue,
                'lost' => $lostValue,
            ],
            'funnel' => [
                'deals_total' => (clone $dealsBase)->count(),
                'deals_won' => $won,
                'deals_lost' => $lost,
                'close_rate_percent' => $totalClosed > 0 ? round(($won / $totalClosed) * 100, 2) : 0.0,
                'quotes_accepted' => $quotesWon,
                'quote_to_win_percent' => (clone $quotesQ)->count() > 0
                    ? round(($won / (clone $quotesQ)->count()) * 100, 2)
                    : 0.0,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function revenue(DashboardScope $scope, ?Carbon $from, ?Carbon $to): array
    {
        $tenantId = $scope->tenantId;
        $orgIds = $scope->organizationIds;

        $payments = $this->scopedPaymentsQuery($scope, $from, $to);

        $monthly = (clone $payments)
            ->where('payment_records.status', PaymentRecord::STATUS_SUCCESS)
            ->select(DB::raw($this->monthExpression('payment_records.created_at').' as period'), DB::raw('coalesce(sum(payment_records.amount),0) as revenue'))
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->map(fn ($r) => ['period' => $r->period, 'revenue' => (float) $r->revenue])
            ->values()
            ->all();

        $byProduct = DB::table('quote_items')
            ->join('quotes', 'quotes.id', '=', 'quote_items.quote_id')
            ->join('deals', 'deals.id', '=', 'quotes.deal_id')
            ->join('payment_records', 'payment_records.quote_id', '=', 'quotes.id')
            ->where('quote_items.tenant_id', $tenantId)
            ->where('payment_records.status', PaymentRecord::STATUS_SUCCESS)
            ->where(function ($q) use ($orgIds): void {
                $q->whereIn('deals.channel_organization_id', $orgIds)
                    ->orWhere(function ($legacy) use ($orgIds): void {
                        $legacy->whereNull('deals.channel_organization_id')
                            ->whereIn('deals.partner_organization_id', $orgIds);
                    });
            })
            ->when($from, fn ($q) => $q->where('payment_records.created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('payment_records.created_at', '<=', $to))
            ->select('quote_items.product_id', 'quote_items.product_name', DB::raw('coalesce(sum(quote_items.line_total),0) as revenue'))
            ->groupBy('quote_items.product_id', 'quote_items.product_name')
            ->orderByDesc('revenue')
            ->limit(20)
            ->get()
            ->map(fn ($r) => [
                'product_id' => $r->product_id ? (int) $r->product_id : null,
                'product_name' => $r->product_name,
                'revenue' => (float) $r->revenue,
            ])
            ->values()
            ->all();

        $byChildOrg = [];
        if ($scope->includesChildren) {
            $byChildOrg = DB::table('payment_records')
                ->join('quotes', 'quotes.id', '=', 'payment_records.quote_id')
                ->join('deals', 'deals.id', '=', 'quotes.deal_id')
                ->where('payment_records.tenant_id', $tenantId)
                ->where('payment_records.status', PaymentRecord::STATUS_SUCCESS)
                ->whereIn(DB::raw('coalesce(deals.channel_organization_id, deals.partner_organization_id)'), $orgIds)
                ->when($from, fn ($q) => $q->where('payment_records.created_at', '>=', $from))
                ->when($to, fn ($q) => $q->where('payment_records.created_at', '<=', $to))
                ->select(
                    DB::raw('coalesce(deals.channel_organization_id, deals.partner_organization_id) as organization_id'),
                    DB::raw('coalesce(sum(payment_records.amount),0) as revenue')
                )
                ->groupBy('organization_id')
                ->orderByDesc('revenue')
                ->get()
                ->map(function ($r): array {
                    $org = Organization::query()->find($r->organization_id);

                    return [
                        'organization_id' => (int) $r->organization_id,
                        'display_name' => $org?->display_name,
                        'revenue' => (float) $r->revenue,
                    ];
                })
                ->values()
                ->all();
        }

        $totals = $this->paymentTotals($scope, $from, $to);

        return [
            'monthly' => $monthly,
            'by_product' => $byProduct,
            'by_child_organization' => $byChildOrg,
            'by_payment_status' => [
                'success' => $totals['successful_payments'],
                'failed' => $totals['failed_payments'],
                'pending' => $totals['pending_payments'],
            ],
            'totals' => $totals,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function commissions(DashboardScope $scope, ?Carbon $from, ?Carbon $to): array
    {
        $base = CommissionAccrual::query()
            ->where('tenant_id', $scope->tenantId)
            ->whereIn('partner_organization_id', $scope->organizationIds);
        $this->applyDateRange($base, $from, $to);

        $totals = $this->commissionTotals($scope, $from, $to);

        $byMonth = (clone $base)
            ->select(DB::raw($this->monthExpression('created_at').' as period'), DB::raw('coalesce(sum(commission_amount),0) as amount'))
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->map(fn ($r) => ['period' => $r->period, 'amount' => (float) $r->amount])
            ->values()
            ->all();

        $byOrg = (clone $base)
            ->select('partner_organization_id', DB::raw('coalesce(sum(commission_amount),0) as amount'))
            ->groupBy('partner_organization_id')
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($r) => [
                'organization_id' => (int) $r->partner_organization_id,
                'amount' => (float) $r->amount,
            ])
            ->values()
            ->all();

        $recent = (clone $base)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (CommissionAccrual $r) => [
                'id' => $r->id,
                'partner_organization_id' => $r->partner_organization_id,
                'commission_amount' => (float) $r->commission_amount,
                'status' => $r->status,
                'quote_id' => $r->quote_id,
                'created_at' => $r->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return [
            'totals' => $totals,
            'by_month' => $byMonth,
            'by_organization' => $byOrg,
            'recent' => $recent,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function licenses(DashboardScope $scope, ?Carbon $from, ?Carbon $to): array
    {
        $tenantId = $scope->tenantId;
        $orgIds = $scope->organizationIds;

        $entitlements = LicenseEntitlement::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('holder_organization_id', $orgIds);

        $allocated = (int) (clone $entitlements)->sum('units_total');
        $consumed = (int) (clone $entitlements)->sum('units_consumed');
        $available = max(0, $allocated - $consumed);

        $transfers = LicenseMovement::query()
            ->where('tenant_id', $tenantId)
            ->where('movement_type', LicenseMovement::TYPE_TRANSFER)
            ->where(function ($q) use ($orgIds): void {
                $q->whereIn('to_organization_id', $orgIds);
            })
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->count();

        $activationsQ = LicenseActivation::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('license_entitlement_id', (clone $entitlements)->select('id'))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to));

        $activationsCount = (clone $activationsQ)->count();

        $activationsByMonth = (clone $activationsQ)
            ->select(DB::raw($this->monthExpression('created_at').' as period'), DB::raw('count(*) as count'))
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->map(fn ($r) => ['period' => $r->period, 'count' => (int) $r->count])
            ->values()
            ->all();

        $byProduct = (clone $entitlements)
            ->select('product_id', DB::raw('coalesce(sum(units_total),0) as allocated'), DB::raw('coalesce(sum(units_consumed),0) as consumed'))
            ->groupBy('product_id')
            ->get()
            ->map(fn ($r) => [
                'product_id' => $r->product_id ? (int) $r->product_id : null,
                'allocated' => (int) $r->allocated,
                'consumed' => (int) $r->consumed,
            ])
            ->values()
            ->all();

        $recentTransfers = LicenseMovement::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('to_organization_id', $orgIds)
            ->orderByDesc('id')
            ->limit(15)
            ->get()
            ->map(fn (LicenseMovement $m) => [
                'id' => $m->id,
                'movement_type' => $m->movement_type,
                'units' => $m->units,
                'to_organization_id' => $m->to_organization_id,
                'created_at' => $m->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return [
            'allocated' => $allocated,
            'consumed' => $consumed,
            'available' => $available,
            'transfers' => $transfers,
            'activations_count' => $activationsCount,
            'activations_by_month' => $activationsByMonth,
            'by_product' => $byProduct,
            'recent_transfers' => $recentTransfers,
            'entitlements_count' => (clone $entitlements)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function activity(DashboardScope $scope, ?Carbon $from, ?Carbon $to, int $limit = 50): array
    {
        $events = [];
        $tenantId = $scope->tenantId;
        $orgIds = $scope->organizationIds;

        Contact::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('channel_organization_id', $orgIds)
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
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

        Company::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('channel_organization_id', $orgIds)
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'name', 'created_at'])
            ->each(function (Company $c) use (&$events): void {
                $events[] = [
                    'type' => 'company_created',
                    'entity_type' => 'company',
                    'entity_id' => $c->id,
                    'title' => $c->name,
                    'occurred_at' => $c->created_at?->toIso8601String(),
                ];
            });

        $dealsQ = Deal::query();
        $this->whereDealChannelScope($dealsQ, $tenantId, $orgIds);
        $dealsQ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
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

        DealHistory::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('deal_id', function ($sub) use ($tenantId, $orgIds): void {
                $sub->from('deals')->select('id')->where('tenant_id', $tenantId)
                    ->where(function ($q) use ($orgIds): void {
                        $q->whereIn('channel_organization_id', $orgIds)
                            ->orWhere(function ($l) use ($orgIds): void {
                                $l->whereNull('channel_organization_id')->whereIn('partner_organization_id', $orgIds);
                            });
                    });
            })
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->each(function (DealHistory $h) use (&$events): void {
                $events[] = [
                    'type' => 'deal_'.$h->type,
                    'entity_type' => 'deal',
                    'entity_id' => $h->deal_id,
                    'title' => $h->type,
                    'occurred_at' => $h->created_at?->toIso8601String(),
                ];
            });

        ContactActivity::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('contact_id', Contact::query()->where('tenant_id', $tenantId)->whereIn('channel_organization_id', $orgIds)->select('id'))
            ->when($from, fn ($q) => $q->where('occurred_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('occurred_at', '<=', $to))
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

        CommissionAccrual::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('partner_organization_id', $orgIds)
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->each(function (CommissionAccrual $c) use (&$events): void {
                $events[] = [
                    'type' => 'commission_generated',
                    'entity_type' => 'commission_accrual',
                    'entity_id' => $c->id,
                    'title' => 'Commission '.$c->status,
                    'occurred_at' => $c->created_at?->toIso8601String(),
                ];
            });

        LicenseMovement::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('to_organization_id', $orgIds)
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->each(function (LicenseMovement $m) use (&$events): void {
                $events[] = [
                    'type' => 'license_'.$m->movement_type,
                    'entity_type' => 'license_movement',
                    'entity_id' => $m->id,
                    'title' => $m->movement_type,
                    'occurred_at' => $m->created_at?->toIso8601String(),
                ];
            });

        CollateralDownload::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('partner_organization_id', $orgIds)
            ->when($from, fn ($q) => $q->where('downloaded_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('downloaded_at', '<=', $to))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->each(function (CollateralDownload $d) use (&$events): void {
                $events[] = [
                    'type' => 'resource_downloaded',
                    'entity_type' => 'collateral_download',
                    'entity_id' => $d->id,
                    'title' => 'Resource download',
                    'occurred_at' => ($d->downloaded_at ?? $d->created_at)?->toIso8601String(),
                ];
            });

        Organization::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('parent_organization_id', [$scope->rootOrganizationId])
            ->where('type', Organization::TYPE_RESELLER)
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->each(function (Organization $o) use (&$events): void {
                $events[] = [
                    'type' => 'reseller_created',
                    'entity_type' => 'organization',
                    'entity_id' => $o->id,
                    'title' => $o->display_name,
                    'occurred_at' => $o->created_at?->toIso8601String(),
                ];
            });

        usort($events, fn ($a, $b) => strcmp((string) ($b['occurred_at'] ?? ''), (string) ($a['occurred_at'] ?? '')));

        return [
            'items' => array_slice($events, 0, $limit),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function team(DashboardScope $scope, ?Carbon $from, ?Carbon $to): array
    {
        $orgId = $scope->rootOrganizationId;
        $tenantId = $scope->tenantId;

        $members = User::query()
            ->with('roleModel')
            ->where('tenant_id', $tenantId)
            ->whereHas('organizationAssignment', fn ($q) => $q->where('organization_id', $orgId))
            ->get();

        $performance = [];
        foreach ($members as $member) {
            $dealsQ = Deal::query()->where('owner_user_id', $member->id);
            $this->whereDealChannelScope($dealsQ, $tenantId, $scope->organizationIds);
            $this->applyDateRange($dealsQ, $from, $to);

            $dealsTotal = (clone $dealsQ)->count();
            $dealsWon = (clone $dealsQ)->where('status', Deal::STATUS_WON)->count();
            $quotesCount = Quote::query()
                ->where('created_by_user_id', $member->id)
                ->where('tenant_id', $tenantId)
                ->whereIn('channel_organization_id', $scope->organizationIds)
                ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
                ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
                ->count();

            $revenue = (float) DB::table('payment_records')
                ->join('quotes', 'quotes.id', '=', 'payment_records.quote_id')
                ->join('deals', 'deals.id', '=', 'quotes.deal_id')
                ->where('payment_records.status', PaymentRecord::STATUS_SUCCESS)
                ->where('deals.owner_user_id', $member->id)
                ->where('payment_records.tenant_id', $tenantId)
                ->where(function ($q) use ($scope): void {
                    $q->whereIn('deals.channel_organization_id', $scope->organizationIds)
                        ->orWhere(function ($l) use ($scope): void {
                            $l->whereNull('deals.channel_organization_id')
                                ->whereIn('deals.partner_organization_id', $scope->organizationIds);
                        });
                })
                ->when($from, fn ($q) => $q->where('payment_records.created_at', '>=', $from))
                ->when($to, fn ($q) => $q->where('payment_records.created_at', '<=', $to))
                ->sum('payment_records.amount');

            $performance[] = [
                'user_id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'role_code' => $member->currentRoleCode(),
                'status' => $member->statusLabel(),
                'deals_count' => $dealsTotal,
                'quotes_count' => $quotesCount,
                'deals_won' => $dealsWon,
                'win_ratio_percent' => $dealsTotal > 0 ? round(($dealsWon / $dealsTotal) * 100, 2) : 0.0,
                'revenue' => round($revenue, 2),
            ];
        }

        usort($performance, fn ($a, $b) => $b['deals_won'] <=> $a['deals_won']);

        return [
            'members' => $members->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role_code' => $u->currentRoleCode(),
                'status' => $u->statusLabel(),
            ])->values()->all(),
            'performance' => $performance,
            'leaderboard' => array_slice($performance, 0, 10),
            'role_counts' => $this->userTotals($scope),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resources(DashboardScope $scope, ?Carbon $from, ?Carbon $to): array
    {
        $totals = $this->resourceTotals($scope, $from, $to);
        $tenantId = $scope->tenantId;
        $orgIds = $scope->organizationIds;

        $byMonth = CollateralDownload::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('partner_organization_id', $orgIds)
            ->when($from, fn ($q) => $q->where('downloaded_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('downloaded_at', '<=', $to))
            ->select(DB::raw($this->monthExpression('downloaded_at').' as period'), DB::raw('count(*) as count'))
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->map(fn ($r) => ['period' => $r->period, 'count' => (int) $r->count])
            ->values()
            ->all();

        $recent = CollateralDownload::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('partner_organization_id', $orgIds)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (CollateralDownload $d) => [
                'id' => $d->id,
                'collateral_id' => $d->collateral_id,
                'user_id' => $d->user_id,
                'downloaded_at' => ($d->downloaded_at ?? $d->created_at)?->toIso8601String(),
            ])
            ->values()
            ->all();

        return array_merge($totals, [
            'by_month' => $byMonth,
            'recent_downloads' => $recent,
        ]);
    }

    /**
     * @return array<string, float|int>
     */
    private function paymentTotals(DashboardScope $scope, ?Carbon $from, ?Carbon $to): array
    {
        $success = (float) (clone $this->scopedPaymentsQuery($scope, $from, $to))
            ->where('payment_records.status', PaymentRecord::STATUS_SUCCESS)
            ->sum('payment_records.amount');
        $failed = (clone $this->scopedPaymentsQuery($scope, $from, $to))
            ->where('payment_records.status', PaymentRecord::STATUS_FAILED)
            ->count();
        $pending = (clone $this->scopedPaymentsQuery($scope, $from, $to))
            ->where('payment_records.status', PaymentRecord::STATUS_PENDING)
            ->count();

        return [
            'total_revenue' => round($success, 2),
            'successful_payments' => (int) (clone $this->scopedPaymentsQuery($scope, $from, $to))
                ->where('payment_records.status', PaymentRecord::STATUS_SUCCESS)->count(),
            'failed_payments' => (int) $failed,
            'pending_payments' => (int) $pending,
        ];
    }

    /**
     * @return array<string, float|int>
     */
    private function commissionTotals(DashboardScope $scope, ?Carbon $from, ?Carbon $to): array
    {
        $base = CommissionAccrual::query()
            ->where('tenant_id', $scope->tenantId)
            ->whereIn('partner_organization_id', $scope->organizationIds);
        $this->applyDateRange($base, $from, $to);

        $sum = fn (string $status) => round((float) (clone $base)->where('status', $status)->sum('commission_amount'), 2);

        return [
            'accrued' => round((float) (clone $base)->sum('commission_amount'), 2),
            'pending' => $sum(CommissionAccrual::STATUS_PENDING),
            'approved' => $sum(CommissionAccrual::STATUS_APPROVED),
            'paid' => $sum(CommissionAccrual::STATUS_PAID),
            'rejected' => $sum(CommissionAccrual::STATUS_VOID),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payoutsSection(DashboardScope $scope, ?Carbon $from, ?Carbon $to): array
    {
        $totals = $this->payoutTotals($scope, $from, $to);

        $recentQ = Payout::query()
            ->where('tenant_id', $scope->tenantId)
            ->whereIn('beneficiary_organization_id', $scope->organizationIds)
            ->orderByDesc('id')
            ->limit(10);
        if ($from) {
            $recentQ->where('created_at', '>=', $from);
        }
        if ($to) {
            $recentQ->where('created_at', '<=', $to);
        }

        $totals['recent'] = $recentQ->get()->map(fn (Payout $p) => [
            'id' => $p->id,
            'payout_number' => $p->payout_number,
            'status' => $p->status,
            'net_amount' => (float) $p->net_amount,
            'paid_at' => $p->paid_at?->toIso8601String(),
        ])->values()->all();

        return $totals;
    }

    /**
     * @return array<string, float|int>
     */
    private function payoutTotals(DashboardScope $scope, ?Carbon $from, ?Carbon $to): array
    {
        $base = Payout::query()
            ->where('tenant_id', $scope->tenantId)
            ->whereIn('beneficiary_organization_id', $scope->organizationIds);
        if ($from) {
            $base->where('created_at', '>=', $from);
        }
        if ($to) {
            $base->where('created_at', '<=', $to);
        }

        $sumNet = fn (string $status) => round((float) (clone $base)->where('status', $status)->sum('net_amount'), 2);

        $lastPaid = (clone $base)->where('status', Payout::STATUS_PAID)->orderByDesc('paid_at')->first();

        $approvedLiability = round((float) CommissionAccrual::query()
            ->where('tenant_id', $scope->tenantId)
            ->whereIn('partner_organization_id', $scope->organizationIds)
            ->where('status', CommissionAccrual::STATUS_APPROVED)
            ->sum('commission_amount'), 2);

        return [
            'pending_approval_count' => (int) (clone $base)->whereIn('status', [Payout::STATUS_SUBMITTED, Payout::STATUS_DRAFT])->count(),
            'processing_amount' => $sumNet(Payout::STATUS_PROCESSING),
            'paid_mtd' => $sumNet(Payout::STATUS_PAID),
            'failed_count' => (int) (clone $base)->where('status', Payout::STATUS_FAILED)->count(),
            'commission_liability_approved' => $approvedLiability,
            'last_payout_amount' => $lastPaid ? (float) $lastPaid->net_amount : 0.0,
            'last_payout_at' => $lastPaid?->paid_at?->toIso8601String(),
            'next_payout_estimate' => $approvedLiability,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function licenseTotals(DashboardScope $scope): array
    {
        $entitlements = LicenseEntitlement::query()
            ->where('tenant_id', $scope->tenantId)
            ->whereIn('holder_organization_id', $scope->organizationIds);

        $allocated = (int) (clone $entitlements)->sum('units_total');
        $consumed = (int) (clone $entitlements)->sum('units_consumed');

        return [
            'allocated' => $allocated,
            'consumed' => $consumed,
            'available' => max(0, $allocated - $consumed),
            'transferred' => LicenseMovement::query()
                ->where('tenant_id', $scope->tenantId)
                ->where('movement_type', LicenseMovement::TYPE_TRANSFER)
                ->whereIn('to_organization_id', $scope->organizationIds)
                ->count(),
            'activations' => LicenseActivation::query()
                ->where('tenant_id', $scope->tenantId)
                ->whereIn('license_entitlement_id', (clone $entitlements)->select('id'))
                ->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function userTotals(DashboardScope $scope): array
    {
        $orgId = $scope->rootOrganizationId;
        $base = User::query()
            ->where('tenant_id', $scope->tenantId)
            ->whereHas('organizationAssignment', fn ($q) => $q->where('organization_id', $orgId));

        $countRole = fn (string $code) => (clone $base)->where('role', $code)->count();

        return [
            'active' => (clone $base)->where('status', User::STATUS_ACTIVE)->count(),
            'inactive' => (clone $base)->where('status', User::STATUS_INACTIVE)->count(),
            'suspended' => (clone $base)->where('status', User::STATUS_SUSPENDED)->count(),
            'consultants' => $countRole(Role::CODE_RESELLER_SALES_CONSULTANT) + $countRole(Role::CODE_PARTNER_SALES_CONSULTANT),
            'managers' => $countRole(Role::CODE_RESELLER_SALES_MANAGER) + $countRole(Role::CODE_PARTNER_SALES_MANAGER),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resourceTotals(DashboardScope $scope, ?Carbon $from, ?Carbon $to): array
    {
        $q = CollateralDownload::query()
            ->where('tenant_id', $scope->tenantId)
            ->whereIn('partner_organization_id', $scope->organizationIds);
        if ($from) {
            $q->where('downloaded_at', '>=', $from);
        }
        if ($to) {
            $q->where('downloaded_at', '<=', $to);
        }

        $top = (clone $q)
            ->select('collateral_id', DB::raw('count(*) as download_count'))
            ->groupBy('collateral_id')
            ->orderByDesc('download_count')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'collateral_id' => (int) $r->collateral_id,
                'download_count' => (int) $r->download_count,
            ])
            ->values()
            ->all();

        return [
            'downloads' => (clone $q)->count(),
            'top_collateral' => $top,
        ];
    }

    private function scopedPaymentsQuery(DashboardScope $scope, ?Carbon $from, ?Carbon $to): Builder
    {
        $orgIds = $scope->organizationIds;
        $query = PaymentRecord::query()
            ->join('quotes', 'quotes.id', '=', 'payment_records.quote_id')
            ->join('deals', 'deals.id', '=', 'quotes.deal_id')
            ->where('payment_records.tenant_id', $scope->tenantId)
            ->where(function ($q) use ($orgIds): void {
                $q->whereIn('deals.channel_organization_id', $orgIds)
                    ->orWhere(function ($legacy) use ($orgIds): void {
                        $legacy->whereNull('deals.channel_organization_id')
                            ->whereIn('deals.partner_organization_id', $orgIds);
                    });
            });

        if ($from) {
            $query->where('payment_records.created_at', '>=', $from);
        }
        if ($to) {
            $query->where('payment_records.created_at', '<=', $to);
        }

        return $query;
    }

    private function lastActivityAt(DashboardScope $scope): ?string
    {
        $tenantId = $scope->tenantId;
        $orgIds = $scope->organizationIds;
        $candidates = [];

        $contactMax = Contact::query()->where('tenant_id', $tenantId)->whereIn('channel_organization_id', $orgIds)->max('updated_at');
        if ($contactMax) {
            $candidates[] = $contactMax;
        }

        $dealMax = Deal::query()->where('tenant_id', $tenantId)
            ->where(function ($q) use ($orgIds): void {
                $q->whereIn('channel_organization_id', $orgIds)
                    ->orWhere(function ($l) use ($orgIds): void {
                        $l->whereNull('channel_organization_id')->whereIn('partner_organization_id', $orgIds);
                    });
            })->max('updated_at');
        if ($dealMax) {
            $candidates[] = $dealMax;
        }

        $commissionMax = CommissionAccrual::query()->where('tenant_id', $tenantId)
            ->whereIn('partner_organization_id', $orgIds)->max('created_at');
        if ($commissionMax) {
            $candidates[] = $commissionMax;
        }

        if ($candidates === []) {
            return null;
        }

        return collect($candidates)->map(fn ($d) => Carbon::parse($d))->max()->toIso8601String();
    }

    private function applyDateRange(Builder $query, ?Carbon $from, ?Carbon $to, ?string $table = null): void
    {
        $col = ($table ? $table.'.' : '').'created_at';
        if ($from) {
            $query->where($col, '>=', $from);
        }
        if ($to) {
            $query->where($col, '<=', $to);
        }
    }

    private function monthExpression(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m', {$column})",
            'pgsql' => "to_char({$column}, 'YYYY-MM')",
            default => "DATE_FORMAT({$column}, '%Y-%m')",
        };
    }
}
