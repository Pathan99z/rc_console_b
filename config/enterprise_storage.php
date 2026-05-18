<?php

declare(strict_types=1);

/**
 * Enterprise file storage — single source of truth for disk selection.
 *
 * Production S3: set FILESYSTEM_DISK=s3 (+ AWS_* credentials).
 * Local dev: FILESYSTEM_DISK=local (default).
 *
 * Optional per-purpose overrides (leave unset to use default_disk):
 * APP_STORAGE_DISK, QUOTE_STORAGE_DISK, COLLATERAL_STORAGE_DISK, IMPORT_STORAGE_DISK
 */
return [

    'default_disk' => env('APP_STORAGE_DISK', env('FILESYSTEM_DISK', 'local')),

    'purpose_disks' => [
        'quote' => env('QUOTE_STORAGE_DISK'),
        'collateral' => env('COLLATERAL_STORAGE_DISK'),
        'import' => env('IMPORT_STORAGE_DISK'),
    ],

    'signed_url_minutes' => (int) env('STORAGE_SIGNED_URL_MINUTES', 10),

    'collateral_signed_url_minutes' => (int) env('COLLATERAL_SIGNED_URL_MINUTES', 10),

];
