<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:5174')],
    // The permissive localhost pattern is a local-dev convenience only — a
    // production deployment must rely solely on FRONTEND_URL above. Browsers
    // set the Origin header from the real page origin (it cannot be spoofed
    // by page content), so this is not remotely exploitable even when left
    // on, but it should not be present in a production CORS policy on
    // principle: a compromised/misconfigured production box should not
    // silently also trust "http://localhost:*" as an allowed caller.
    'allowed_origins_patterns' => env('APP_ENV') === 'production' ? [] : ['#^http://localhost:\d+$#'],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
