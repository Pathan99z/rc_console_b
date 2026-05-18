<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enterprise cache TTLs (seconds)
    |--------------------------------------------------------------------------
    */

    'ttl' => [
        'list_companies' => 600,
        'list_contacts' => 300,
        'list_deals' => 600,
        'list_pipelines' => 900,
        'list_quotes' => 600,
        'list_products' => 600,
        'list_collaterals' => 600,
        'notification_unread' => 30,
        'user_bootstrap' => 300,
        'login_dashboard_global' => 600,
        'login_dashboard_company' => 300,
        'login_dashboard_partner' => 300,
        'login_dashboard_reseller' => 300,
        'org_dashboard_overview' => 600,
        'org_dashboard_pipeline' => 600,
        'org_dashboard_revenue' => 600,
        'org_dashboard_commissions' => 600,
        'org_dashboard_payouts' => 600,
        'org_dashboard_licenses' => 600,
        'org_dashboard_resources' => 600,
        'org_dashboard_activity' => 120,
        'org_dashboard_team' => 300,
    ],

    'version_ttl_days' => 30,

    'stampede_lock_seconds' => 10,

    'mail_queue' => env('ENTERPRISE_CACHE_MAIL_QUEUE', true),

];
