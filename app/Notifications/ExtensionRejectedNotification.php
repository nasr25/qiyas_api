<?php

namespace App\Notifications;

use App\Models\ExtensionRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ExtensionRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly ExtensionRequest $extensionRequest) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('تم رفض طلب التمديد | Extension Rejected')
            ->line('Your extension request has been rejected.')
            ->line("Notes: {$this->extensionRequest->reviewer_notes}");
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'extension_rejected',
            'extension_id' => $this->extensionRequest->id,
            'message_ar' => 'تم رفض طلب التمديد الخاص بك',
            'message_en' => 'Your extension request has been rejected',
        ];
    }
}
