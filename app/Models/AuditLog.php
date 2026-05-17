<?php

namespace App\Models;

use App\Support\Audit\AuditLogTaxonomyResolver;
use App\Support\Audit\AuditPayloadRedactor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'organization_id',
        'correlation_id',
        'user_id',
        'module',
        'action',
        'entity_type',
        'entity_id',
        'before',
        'after',
        'metadata',
        'ip_address',
        'user_agent',
        'event_key',
        'source',
        'immutable_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'metadata' => 'array',
            'immutable_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AuditLog $log): void {
            $log->before = AuditPayloadRedactor::redact($log->before);
            $log->after = AuditPayloadRedactor::redact($log->after);
            $log->metadata = AuditPayloadRedactor::redact($log->metadata);

            $log->event_key ??= AuditLogTaxonomyResolver::resolve(
                (string) $log->module,
                (string) $log->action,
                (string) $log->entity_type,
                $log->before,
                $log->after
            );

            if ($log->immutable_at === null) {
                $log->immutable_at = now();
            }

            if ($log->correlation_id === null && app()->bound('request')) {
                try {
                    $correlation = request()->attributes->get('correlation_id');
                    if (is_string($correlation) && $correlation !== '') {
                        $log->correlation_id = $correlation;
                    }
                } catch (\Throwable) {
                    // Not in HTTP context
                }
            }

            if ($log->source === null || $log->source === '') {
                $log->source = 'application';
            }
        });

        static::updating(function (): void {
            throw new \LogicException('Audit log records are immutable.');
        });

        static::deleting(function (): void {
            throw new \LogicException('Audit log records cannot be deleted via Eloquent.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Effective enterprise key (persisted or derived from legacy module/action).
     */
    public function resolvedEventKey(): ?string
    {
        if ($this->event_key !== null && $this->event_key !== '') {
            return $this->event_key;
        }

        return AuditLogTaxonomyResolver::resolve(
            (string) $this->module,
            (string) $this->action,
            (string) $this->entity_type,
            $this->before,
            $this->after
        );
    }
}
