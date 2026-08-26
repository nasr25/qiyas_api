<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * The platform's only recurring job.
 *
 * `qiyas:mark-overdue` and `qiyas:send-reminders` were scheduled here until
 * the legacy Document authoring path was retired, which deleted both
 * commands — leaving the scheduler invoking names that no longer resolved
 * and failing twice a day in every environment. Their behaviour is fully
 * covered by compliance:process-sla, which detects SLA warnings, SLA
 * breaches AND overdue requirements in one pass and deduplicates every
 * notification it queues.
 *
 * withoutOverlapping() keeps a slow run from stacking on the next tick.
 * See docs/deployment/production-deployment.md for the worker and scheduler
 * configuration this requires.
 */
Schedule::command('compliance:process-sla')->everyThirtyMinutes()->withoutOverlapping();
