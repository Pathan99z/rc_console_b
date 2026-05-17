<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\DealHistory;
use App\Models\User;
use App\Support\Audit\AuditTaxonomyReverseIndex;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class AuditLogQueryService
{
    public function assertCanAccessAuditConsole(User $viewer): void
    {
        if (! ($viewer->isGlobalAdmin() || $viewer->isCompanyAdmin())) {
            abort(403, 'You are not allowed to access business audit logs.');
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function tenantScopeIds(User $viewer, array $filters): array
    {
        if ($viewer->isGlobalAdmin()) {
            if (isset($filters['tenant_id']) && $filters['tenant_id'] !== null && $filters['tenant_id'] !== '') {
                return [(int) $filters['tenant_id']];
            }

            return [];
        }

        return [(int) $viewer->tenant_id];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateAuditLogs(User $viewer, array $filters, int $perPage): LengthAwarePaginator
    {
        $this->assertCanAccessAuditConsole($viewer);
        $q = $this->auditQueryBase($viewer, $filters)->orderByDesc('created_at');

        /** @phpstan-ignore-next-line */
        return $q->paginate($perPage);
    }

    /**
     * Unified paginator over audit_logs and optionally deal_histories.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateUnified(User $viewer, array $filters, int $perPage): LengthAwarePaginator
    {
        $this->assertCanAccessAuditConsole($viewer);
        $includeDealHistories = (bool) ($filters['include_deal_histories'] ?? true);

        if (! $includeDealHistories) {
            /** @phpstan-ignore-next-line */
            return $this->paginateAuditLogs($viewer, $filters, $perPage);
        }

        $tenantIds = $this->tenantScopeIds($viewer, $filters);
        $page = (int) ($filters['page'] ?? 1);
        $page = max(1, $page);

        $auditCounts = clone $this->auditQueryBase($viewer, $filters);
        /** @phpstan-ignore-next-line */
        $auditTotal = $auditCounts->count();

        $dealCounts = clone $this->dealHistoryBaseQuery($viewer, $filters);
        $dealTotal = (clone $dealCounts)->count();

        $total = $auditTotal + $dealTotal;

        /** @phpstan-ignore-next-line */
        $auditRows = $this->auditQueryBase($viewer, $filters)
            ->with(['user:id,name,email'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(5000)
            ->get();

        $dealRows = $this->dealHistoryBaseQuery($viewer, $filters)
            ->with(['user:id,name,email', 'deal'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(5000)
            ->get();

        $combined = collect()
            ->merge($auditRows->map(fn ($row) => $this->normalizeAuditRow($row)))
            ->merge($dealRows->map(fn ($row) => $this->normalizeDealHistoryRow($row)));

        $sortKey = fn (array $a, array $b): int => [$b['occurred_at'] ?? '', $b['numeric_id']] <=> [$a['occurred_at'] ?? '', $a['numeric_id']];
        /** @phpstan-ignore-next-line */
        $combined = $combined->sort($sortKey)->values();

        $slice = $combined->slice(($page - 1) * $perPage, $perPage)->values();

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $slice,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'pageName' => 'page']
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function findUnified(User $viewer, string $publicId): ?array
    {
        $this->assertCanAccessAuditConsole($viewer);
        if (! preg_match('#^(?<type>audit|dh)-(?<nid>\d+)$#', $publicId, $m)) {
            return null;
        }
        $type = $m['type'];
        $nid = (int) $m['nid'];

        if ($type === 'audit') {
            $row = AuditLog::query()->with(['user:id,name,email', 'tenant:id,name'])
                /** @phpstan-ignore-next-line */
                ->find($nid);
            if (! $row) {
                return null;
            }

            /** @phpstan-ignore-next-line */
            if ($this->rowOutsideTenantScope($viewer, [(int) $row->tenant_id])) {
                return null;
            }

            return $this->normalizeAuditRow($row);
        }

        /** @phpstan-ignore-next-line */
        $hist = DealHistory::query()->with(['user:id,name,email'])->find($nid);
        if (! $hist) {
            return null;
        }

        /** @phpstan-ignore-next-line */
        if ($this->rowOutsideTenantScope($viewer, [(int) $hist->tenant_id])) {
            return null;
        }

        return $this->normalizeDealHistoryRow($hist);
    }

    /**
     * Public ID format audit-{id}, dh-{id}
     *
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Support\Collection<int, array<string,mixed>>
     */
    public function collectForCsv(User $viewer, array $filters, int $maxRows): \Illuminate\Support\Collection
    {
        $paginator = $this->paginateUnified($viewer, array_merge($filters, ['page' => 1]), $maxRows);
        /** @phpstan-ignore-next-line */
        return collect($paginator->items());
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function auditQueryBase(User $viewer, array $filters): Builder
    {
        $tenantIds = $this->tenantScopeIds($viewer, $filters);

        /** @phpstan-ignore-next-line */
        $query = AuditLog::query()->where(function ($q) use ($filters): void {
            if (($filters['include_archived'] ?? false) !== true && ($filters['include_archived'] ?? false) !== '1') {
                $q->whereNull('audit_logs.archived_at');
            }
        });

        if ($tenantIds !== []) {
            $query->whereIn('audit_logs.tenant_id', $tenantIds);
        }

        if (! empty($filters['source'])) {
            $sourceRaw = trim((string) $filters['source']);
            $s = strtolower($sourceRaw);
            if (in_array($s, ['deal_histories', 'deal_history'], true)) {
                $query->whereRaw('1 = 0');
            } elseif ($s === 'application') {
                $query->where(function ($q): void {
                    $q->where('audit_logs.source', '=', 'application')
                        ->orWhereNull('audit_logs.source');
                });
            } else {
                $query->where('audit_logs.source', '=', $sourceRaw);
            }
        }

        /** @phpstan-ignore-next-line */
        if ($from = $this->maybeDateTime($filters['date_from'] ?? null)) {
            $query->where('audit_logs.created_at', '>=', $from);
        }
        /** @phpstan-ignore-next-line */
        if ($to = $this->maybeDateTime($filters['date_to'] ?? null)) {
            $query->where('audit_logs.created_at', '<=', $to);
        }

        if (! empty($filters['module'])) {
            /** @phpstan-ignore-next-line */
            $query->where('audit_logs.module', '=', (string) $filters['module']);
        }

        if (! empty($filters['actor_user_id'])) {
            /** @phpstan-ignore-next-line */
            $query->where('audit_logs.user_id', '=', (int) $filters['actor_user_id']);
        }

        if (! empty($filters['entity_type'])) {
            /** @phpstan-ignore-next-line */
            $query->where('audit_logs.entity_type', '=', (string) $filters['entity_type']);
        }

        if (isset($filters['entity_id']) && $filters['entity_id'] !== null && $filters['entity_id'] !== '') {
            /** @phpstan-ignore-next-line */
            $query->where('audit_logs.entity_id', '=', (int) $filters['entity_id']);
        }

        if (! empty($filters['organization_id'])) {
            /** @phpstan-ignore-next-line */
            $query->where('audit_logs.organization_id', '=', (int) $filters['organization_id']);
        }

        if (! empty($filters['event_key'])) {
            /** @phpstan-ignore-next-line */
            $this->applyEventKeyFilter((string) $filters['event_key'], $query);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function dealHistoryBaseQuery(User $viewer, array $filters): Builder
    {
        $tenantIds = $this->tenantScopeIds($viewer, $filters);

        /** @phpstan-ignore-next-line */
        $q = DealHistory::query()
            /** @phpstan-ignore-next-line */
            ->when($tenantIds !== [], fn (Builder $b) => $b->whereIn('tenant_id', $tenantIds))
            /** @phpstan-ignore-next-line */
            ->when(($from = $this->maybeDateTime($filters['date_from'] ?? null)), fn (Builder $b) => $b->where('created_at', '>=', $from));

        /** @phpstan-ignore-next-line */
        if ($to = $this->maybeDateTime($filters['date_to'] ?? null)) {
            $q->where('created_at', '<=', $to);
        }

        if (! empty($filters['source'])) {
            $s = strtolower(trim((string) $filters['source']));
            if (! in_array($s, ['deal_histories', 'deal_history'], true)) {
                $q->whereRaw('1 = 0');
            }
        }

        if (! empty($filters['actor_user_id'])) {
            /** @phpstan-ignore-next-line */
            $q->where('user_id', '=', (int) $filters['actor_user_id']);
        }

        if (! empty($filters['entity_type']) && strtolower((string) $filters['entity_type']) !== 'deal') {
            /** @phpstan-ignore-next-line */
            $q->whereRaw('1 = 0');
        }

        if (isset($filters['entity_id']) && $filters['entity_id'] !== null && $filters['entity_id'] !== '') {
            /** @phpstan-ignore-next-line */
            $q->where('deal_id', '=', (int) $filters['entity_id']);
        }

        if (! empty($filters['module'])
            && strtolower((string) $filters['module']) !== 'deal'
            && strtolower((string) $filters['module']) !== 'deals') {
            /** @phpstan-ignore-next-line */
            $q->whereRaw('1 = 0');
        }

        if (! empty($filters['organization_id'])) {
            $orgId = (int) $filters['organization_id'];
            $q->whereExists(function ($sub) use ($orgId): void {
                /** @phpstan-ignore-next-line */
                $sub->select(DB::raw(1))
                    ->from('deals')
                    ->whereColumn('deals.id', 'deal_histories.deal_id')
                    ->where(function ($w) use ($orgId): void {
                        $w->where('deals.channel_organization_id', '=', $orgId)
                            ->orWhere('deals.partner_organization_id', '=', $orgId);
                    });
            });
        }

        if (! empty($filters['event_key'])) {
            $ek = self::sanitizePlain((string) $filters['event_key']);
            $expr = $this->dealHistoryEventExpression();
            $q->whereRaw("({$expr}) = ?", [$ek]);
        }

        /** @phpstan-ignore-next-line */
        return $q;
    }

    private static function sanitizePlain(string $value): string
    {
        return preg_replace('/[^a-zA-Z0-9._-]/', '', $value) ?? '';
    }

    private function maybeDateTime(mixed $v): ?Carbon
    {
        if ($v === null || $v === '') {
            return null;
        }
        try {
            return Carbon::parse((string) $v);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  list<int>  $rowTenantIds
     */
    private function rowOutsideTenantScope(User $viewer, array $rowTenantIds): bool
    {
        if ($viewer->isGlobalAdmin()) {
            return false;
        }
        foreach ($rowTenantIds as $tid) {
            if ((int) $tid === (int) $viewer->tenant_id) {
                return false;
            }
        }

        return true;
    }

    /** @phpstan-ignore-next-line */
    private function normalizeAuditRow(AuditLog $row): array
    {
        return [
            'stream' => 'audit_log',
            'public_id' => 'audit-'.$row->id,
            'numeric_id' => $row->id,
            'occurred_at' => $row->created_at?->toIso8601String(),
            'tenant_id' => $row->tenant_id,
            'organization_id' => $row->organization_id,
            'correlation_id' => $row->correlation_id,
            'actor_user' => $row->user ? [
                'id' => $row->user->id,
                'name' => $row->user->name,
                'email' => $row->user->email,
            ] : null,
            'module' => $row->module,
            'action' => $row->action,
            'event_key' => $row->resolvedEventKey(),
            'entity_type' => $row->entity_type,
            'entity_id' => $row->entity_id,
            'source' => $row->source,
            'ip_address' => $row->ip_address,
            'user_agent' => $row->user_agent,
            'before' => $row->before,
            'after' => $row->after,
            'metadata' => $row->metadata,
            'immutable_at' => $row->immutable_at?->toIso8601String(),
            'archived_at' => $row->archived_at?->toIso8601String(),
        ];
    }

    /** @phpstan-ignore-next-line */
    private function normalizeDealHistoryRow(DealHistory $row): array
    {
        $eventKey = $this->dealHistoryResolvedKey($row->type ?? '', $row->to_value);

        return [
            'stream' => 'deal_history',
            'public_id' => 'dh-'.$row->id,
            'numeric_id' => $row->id,
            'occurred_at' => $row->created_at?->toIso8601String(),
            'tenant_id' => $row->tenant_id,
            'organization_id' => $row->deal?->channel_organization_id ?? $row->deal?->partner_organization_id,
            'correlation_id' => null,
            'actor_user' => $row->user ? [
                'id' => $row->user->id,
                'name' => $row->user->name,
                'email' => $row->user->email,
            ] : null,
            'module' => 'deal',
            'action' => (string) $row->type,
            'event_key' => $eventKey,
            'entity_type' => 'deal',
            'entity_id' => $row->deal_id,
            'source' => 'deal_histories',
            'ip_address' => null,
            'user_agent' => null,
            'before' => ['from_value' => $row->from_value, 'notes' => $row->notes],
            'after' => ['to_value' => $row->to_value],
            'metadata' => $row->meta,
            'immutable_at' => null,
            'archived_at' => null,
        ];
    }

    private function dealHistoryResolvedKey(string $type, ?string $toValue): ?string
    {
        $toLower = strtolower((string) $toValue);

        return match ($type) {
            'created' => \App\Support\Audit\BusinessAuditEventKeys::DEALS_CREATED,
            'owner_changed' => \App\Support\Audit\BusinessAuditEventKeys::DEALS_OWNER_CHANGED,
            'stage_moved' => \App\Support\Audit\BusinessAuditEventKeys::DEALS_STAGE_CHANGED,
            'status_changed' => match ($toLower) {
                'won' => \App\Support\Audit\BusinessAuditEventKeys::DEALS_WON,
                'lost' => \App\Support\Audit\BusinessAuditEventKeys::DEALS_LOST,
                default => \App\Support\Audit\BusinessAuditEventKeys::DEALS_STAGE_CHANGED,
            },
            default => null,
        };
    }

    private function applyEventKeyFilter(string $eventKey, Builder $query): void
    {
        $pairs = AuditTaxonomyReverseIndex::moduleActionTuples($eventKey);

        /** @phpstan-ignore-next-line */
        $query->where(function ($q) use ($eventKey, $pairs): void {
            $q->where('audit_logs.event_key', '=', $eventKey);

            foreach ($pairs as [$module, $action]) {
                $q->orWhere(function ($sq) use ($module, $action): void {
                    $sq->where('audit_logs.module', '=', $module)
                        ->where('audit_logs.action', '=', $action);
                });
            }
        });
    }

    /**
     * SQL fragment for SQLite / MySQL case expression (no bindings inside CASE).
     */
    private function dealHistoryEventExpression(): string
    {
        return "CASE WHEN type = 'created' THEN 'deals.created' "
            ."WHEN type = 'owner_changed' THEN 'deals.owner_changed' "
            ."WHEN type = 'stage_moved' THEN 'deals.stage_changed' "
            ."WHEN type = 'status_changed' AND lower(ifnull(to_value,'')) = 'won' THEN 'deals.won' "
            ."WHEN type = 'status_changed' AND lower(ifnull(to_value,'')) = 'lost' THEN 'deals.lost' "
            ."WHEN type = 'status_changed' THEN 'deals.stage_changed' "
            .'ELSE '.$this->fallbackUnknownDealEventSqlLiteral().' END';
    }

    /**
     * @return numeric-string|false
     */
    private function fallbackUnknownDealEventSqlLiteral(): string
    {
        return "'deals.history'";
    }
}
