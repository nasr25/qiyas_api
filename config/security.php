<?php

/**
 * Rate limits, expressed as requests per minute.
 *
 * These live in config (not read via env() at the call site) so that
 * `php artisan config:cache` — which stops the .env file from being loaded
 * at all — still honours what a deployment configured.
 *
 * The defaults are set for internal, authenticated use: high enough that a
 * person working normally never meets them, low enough to bound automated
 * abuse. See docs/deployment/production-deployment.md.
 */
return [
    // Unauthenticated credential testing, keyed by username + IP.
    'login_rate_limit' => (int) env('LOGIN_RATE_LIMIT_PER_MINUTE', 10),

    // Authenticated, expensive reads: reports, exports, template generation.
    'reports_rate_limit' => (int) env('REPORTS_RATE_LIMIT_PER_MINUTE', 60),

    // Authenticated writes that consume disk and parser time: evidence and
    // XLSX uploads.
    'uploads_rate_limit' => (int) env('UPLOADS_RATE_LIMIT_PER_MINUTE', 30),
];
