<?php
return [
    'enabled' => (bool) env('NOTES_REALTIME_ENABLED', false),
    'endpoint' => env('NOTES_REALTIME_ENDPOINT', ''),
    // Exact browser/WebView origins; no wildcard. Configure before activation.
    'origins' => array_filter(explode(',', env('NOTES_REALTIME_ORIGINS', ''))),
    'scope_key' => env('APP_KEY', ''),
    'identity_endpoint' => env('IDENTITY_ENDPOINT', ''),
    'identity_header' => env('IDENTITY_HEADER', ''),
];
