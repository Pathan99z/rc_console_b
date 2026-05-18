<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Facades\Storage;

trait ConfiguresEnterpriseStorage
{
    protected function fakeEnterpriseStorage(string $disk = 'local'): void
    {
        Storage::fake($disk);
        config([
            'enterprise_storage.default_disk' => $disk,
            'enterprise_storage.purpose_disks' => [
                'quote' => null,
                'collateral' => null,
                'import' => null,
            ],
        ]);
    }
}
