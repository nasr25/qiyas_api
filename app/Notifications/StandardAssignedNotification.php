<?php

namespace App\Notifications;

use App\Models\Standard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifies users of a department when a standard is assigned to it.
 */
class StandardAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Standard $standard) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('معيار جديد مُسند إلى إدارتك | New Standard Assigned')
            ->line("Standard: {$this->standard->standard_number} — {$this->standard->name_ar}")
            ->action('View Standard', url("/standards/{$this->standard->id}"))
            ->line('يرجى رفع مستندات الإثبات المطلوبة لهذا المعيار.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'        => 'standard_assigned',
            'standard_id' => $this->standard->id,
            'number'      => $this->standard->standard_number,
            'message_ar'  => "تم إسناد معيار جديد إلى إدارتك: {$this->standard->standard_number} — {$this->standard->name_ar}",
            'message_en'  => "A new standard was assigned to your department: {$this->standard->standard_number}",
        ];
    }
}
