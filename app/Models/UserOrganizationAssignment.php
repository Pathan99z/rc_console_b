<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserOrganizationAssignment extends Model
{
    protected $table = 'user_organization_assignments';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'organization_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
