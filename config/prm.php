<?php

return [
    'invite_expiry_days' => (int) env('PRM_INVITE_EXPIRY_DAYS', 7),
    'invite_accept_url' => env('PRM_INVITE_ACCEPT_URL', rtrim((string) env('FRONTEND_URL', env('APP_URL', 'http://localhost:5173')), '/').'/partner/accept'),
    'auto_verify_invited_users' => filter_var(env('PRM_AUTO_VERIFY_INVITED_USERS', false), FILTER_VALIDATE_BOOL),
];
