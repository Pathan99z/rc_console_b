<?php

namespace App\Repositories;

use App\Models\DealHistory;

class DealHistoryRepository
{
    public function create(array $data): DealHistory
    {
        return DealHistory::query()->create($data);
    }
}
