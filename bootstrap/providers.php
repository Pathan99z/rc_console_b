<?php

use App\Providers\AppServiceProvider;
use App\Providers\CacheServiceProvider;
use App\Providers\NotificationServiceProvider;
use App\Providers\OrganizationMailServiceProvider;

return [
    OrganizationMailServiceProvider::class,
    NotificationServiceProvider::class,
    CacheServiceProvider::class,
    AppServiceProvider::class,
];
