<?php

namespace App\Events\Notifications;

use Illuminate\Foundation\Events\Dispatchable;

class DealStageChanged
{
    use Dispatchable;

    public function __construct(
        public readonly int $dealId,
        public readonly int $pipelineStageId,
        public readonly string $stageName,
    ) {}
}
