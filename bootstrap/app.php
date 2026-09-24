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
        $middleware->prepend(\App\Http\Middleware\MeasurePerformance::class);
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO
        );

        // Encrypted note payloads are opaque. Preserve whitespace and explicit empty values.
        $notesRequest = fn (Request $request) => $request->is('api/v1/notescontroller/*');
        $middleware->trimStrings(except: [$notesRequest]);
        $middleware->convertEmptyStringsToNull(except: [$notesRequest]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Laravel's routing pipeline renders exceptions before outer middleware
        // can catch them. Register here so actual upstream failures stay controlled.
        $exceptions->render(function (\Illuminate\Http\Client\RequestException|\Illuminate\Http\Client\ConnectionException $error, Request $request) {
            if ($request->is('api/*')) return (new \App\Support\UpstreamServiceErrors())->render($request, $error);
        });
        // Upstream exception messages can contain response bodies and token URLs.
        // Record only the failure category, never the original exception object.
        $exceptions->report(function (\Illuminate\Http\Client\RequestException $error) {
            \Illuminate\Support\Facades\Log::warning('Upstream HTTP request failed', ['upstream_status' => $error->response->status()]);
            return false;
        });
        $exceptions->report(function (\Illuminate\Http\Client\ConnectionException $error) {
            \Illuminate\Support\Facades\Log::warning('Upstream connection failed');
            return false;
        });
    })
    ->create();
