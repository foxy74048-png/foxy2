<?php

use App\Ai\AiServiceProvider;
use App\Platform\PlatformServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    PlatformServiceProvider::class,
    AiServiceProvider::class,
];
