<?php

namespace App\Console\Commands;

use App\Models\AssessmentCycle;
use App\Models\Setting;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Sends email reminders to department coordinators for approaching deadlines.
 */
class SendDeadlineReminders extends Command
{
    protected $signature   = 'qiyas:send-reminders';
    protected $description = 'Send deadline reminder notifications to users';

    public function handle(): int
    {
        $reminderDays = Setting::get('notifications', 'reminder_days', 7);
        $targetDate   = today()->addDays($reminderDays);

        $standards = Standard::whereNotNull('due_date')
            ->whereDate('due_date', $targetDate)
            ->where('is_active', true)
            ->with('departments.users')
            ->get();

        $sent = 0;
        foreach ($standards as $standard) {
            foreach ($standard->departments as $department) {
                foreach ($department->users as $user) {
                    if (!$user->is_active) continue;

                    $user->notify(new \App\Notifications\DeadlineReminderNotification($standard, $targetDate));
                    $sent++;
                }
            }
        }

        $this->info("Sent {$sent} deadline reminders for {$standards->count()} standards.");
        return self::SUCCESS;
    }
}
