<?php

namespace App\Services\Dashboard\Builders;

use App\Models\User;

interface LoginDashboardBuilderContract
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function build(User $actor, array $filters = []): array;
}
