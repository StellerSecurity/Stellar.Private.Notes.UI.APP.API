<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /**
         * Trust Azure (and other reverse proxies) so client IP and HTTPS scheme
         * are detected correctly.
         *
         * - `at: '*'` means "trust all proxies". In an Azure App Service / App Gateway
         *   setup this is usually fine, because traffic only reaches your app through
         *   Azure's frontends.
         *
         * If you later want to lock this down even more, you can replace '*' with a list
         * of specific proxy IPs or CIDR ranges, or wire it to an env variable.
         */
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO
        );

        // You can also configure global middleware or middleware groups here later, e.g.:
        // $middleware->append(\App\Http\Middleware\YourGlobalMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Register custom exception handling, report/renderer callbacks, etc. here if needed.
    })
    ->create();
