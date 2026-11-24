<?php

$corsOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000,https://myroicard.com'))
)));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $corsOrigins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['X-Idempotent-Replayed'],
    'max_age' => 0,
    // Never combine credential support with a wildcard origin.
    'supports_credentials' => env(
        'CORS_SUPPORTS_CREDENTIALS',
        ! in_array('*', $corsOrigins, true),
    ),
];
