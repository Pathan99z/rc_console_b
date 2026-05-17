<?php

declare(strict_types=1);

return [
    'searchable_days' => (int) env('AUDIT_SEARCHABLE_DAYS', 365),
    /*
     * Rows remain in-database but flagged archived_at once older than searchable_days,
     * when the audit:archive command runs.
     */
    'archive_when_older_than_days' => (int) env('AUDIT_ARCHIVE_AFTER_DAYS', 365),

    /*
     * Hard delete cutoff from created_at (optional — run audit:purge only when aligned with policy).
     */
    'purge_when_older_than_days' => (int) env('AUDIT_PURGE_AFTER_DAYS', 2555),

    'correlation_header' => 'X-Correlation-Id',

    'csv_max_rows_inline' => (int) env('AUDIT_EXPORT_CSV_MAX_ROWS', 10_000),
];
