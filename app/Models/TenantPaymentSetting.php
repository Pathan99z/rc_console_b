<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantPaymentSetting extends Model
{
    public const MODE_SANDBOX = 'sandbox';

    public const MODE_LIVE = 'live';

    protected $fillable = [
        'tenant_id',
        'payfast_mode',
        'merchant_id',
        'merchant_key_encrypted',
        'passphrase_encrypted',
        'return_url',
        'cancel_url',
        'notify_url',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
