<?php

namespace App\Repositories;

use App\Models\ContactActivity;

class ContactActivityRepository
{
    public function create(array $data): ContactActivity
    {
        return ContactActivity::query()->create($data);
    }
}
