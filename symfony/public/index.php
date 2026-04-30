<?php

use App\EventSubscriber\FatalErrorHandlerSubscriber;
use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

// Issue #13 — register the friendly-fatal renderer BEFORE Symfony boots.
// Symfony's own ErrorHandler also calls register_shutdown_function during
// kernel boot, and PHP runs shutdown functions FIFO. Registering ours
// first means it executes before Symfony's, so headers_sent() is still
// false and we can emit our 503 page cleanly. If our handler decides not
// to render (any non-OOM/non-timeout fatal, or no error at all), Symfony's
// own handler still runs after ours.
register_shutdown_function([FatalErrorHandlerSubscriber::class, 'shutdownEntrypoint']);

return function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
