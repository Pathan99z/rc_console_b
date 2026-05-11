<?php

namespace App\Repositories;

use App\Models\PaymentRecord;

class PaymentRecordRepository
{
    public function findById(int $id): ?PaymentRecord
    {
        return PaymentRecord::query()->find($id);
    }

    public function create(array $payload): PaymentRecord
    {
        return PaymentRecord::query()->create($payload);
    }

    public function update(PaymentRecord $record, array $payload): PaymentRecord
    {
        $record->update($payload);

        return $record->refresh();
    }
}
