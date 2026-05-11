<?php

return [
    'sandbox_process_url' => env('PAYFAST_SANDBOX_URL', 'https://sandbox.payfast.co.za/eng/process'),
    'live_process_url' => env('PAYFAST_LIVE_URL', 'https://www.payfast.co.za/eng/process'),
    'fallback_merchant_id' => env('PAYFAST_MERCHANT_ID'),
    'fallback_merchant_key' => env('PAYFAST_MERCHANT_KEY'),
    'fallback_passphrase' => env('PAYFAST_PASSPHRASE'),
    'fallback_mode' => env('PAYFAST_MODE', 'sandbox'),
    'default_return_path' => env('PAYFAST_DEFAULT_RETURN_PATH', '/billing/payfast/return'),
    'default_cancel_path' => env('PAYFAST_DEFAULT_CANCEL_PATH', '/billing/payfast/cancel'),
    'default_notify_url' => env('PAYFAST_NOTIFY_URL'),
];
