<?php

namespace App\Support\OrganizationMail;

/**
 * Runtime mail resolution stack for hierarchical SMTP selection.
 *
 * @phpstan-type StackFrame array{0: ?int, 1: ?int}
 */
final class OrganizationMailContext
{
    /** @var list<StackFrame> */
    private static array $stack = [];

    /**
     * @param  callable(): mixed  $callback
     */
    public static function run(?int $tenantId, ?int $organizationId, callable $callback): mixed
    {
        self::push($tenantId, $organizationId);
        try {
            return $callback();
        } finally {
            self::pop();
        }
    }

    public static function push(?int $tenantId, ?int $organizationId): void
    {
        self::$stack[] = [$tenantId, $organizationId];
    }

    public static function pop(): void
    {
        array_pop(self::$stack);
    }

    public static function currentTenantId(): ?int
    {
        $frame = end(self::$stack);

        return $frame !== false ? $frame[0] : null;
    }

    public static function currentOrganizationId(): ?int
    {
        $frame = end(self::$stack);

        return $frame !== false ? $frame[1] : null;
    }
}
