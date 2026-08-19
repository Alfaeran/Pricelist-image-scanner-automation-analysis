<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\TelescopeServiceProvider::class,
    App\Providers\WhatsAppServiceProvider::class,
    // NativeAppServiceProvider is deliberately absent: NativePHP owns it via
    // config('nativephp.provider') and boots it once. See that class.
];
