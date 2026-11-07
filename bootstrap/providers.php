<?php

use App\Ai\AiServiceProvider;
use App\Commerce\CommerceServiceProvider;
use App\Platform\PlatformServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    PlatformServiceProvider::class,
    AiServiceProvider::class,
    CommerceServiceProvider::class,
];
