<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily: Mark overdue documents
Schedule::command('qiyas:mark-overdue')->dailyAt('01:00');

// Daily: Send deadline reminders
Schedule::command('qiyas:send-reminders')->dailyAt('08:00');

// Every 30 minutes: detect SLA warnings/breaches and overdue requirements
// (Phase 2 workflow). See docs/windows-scheduler-and-queues.md for the
// deployment configuration (Windows Task Scheduler + queue worker).
Schedule::command('compliance:process-sla')->everyThirtyMinutes()->withoutOverlapping();
