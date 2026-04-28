<?php

namespace App\Repositories;

use App\Models\AuditLog;

class AuditLogRepository
{
    public function create(array $payload): AuditLog
    {
        return AuditLog::query()->create($payload);
    }
}
