<?php

namespace App\Notifications;

use App\Models\Standard;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DeadlineReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Standard $standard,
        private readonly Carbon   $dueDate
    ) {}

    public function via(object $notifiable): array { return ['database', 'mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('تذكير: موعد انتهاء المعيار | Standard Deadline Reminder')
            ->line("Standard '{$this->standard->name_en}' is due on {$this->dueDate->toDateString()}.")
            ->action('View Standards', url('/standards'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'        => 'deadline_reminder',
            'standard_id' => $this->standard->id,
            'due_date'    => $this->dueDate->toDateString(),
            'message_ar'  => "تذكير: موعد انتهاء المعيار {$this->standard->name_ar} يقترب",
            'message_en'  => "Reminder: Standard '{$this->standard->name_en}' deadline approaching",
        ];
    }
}
