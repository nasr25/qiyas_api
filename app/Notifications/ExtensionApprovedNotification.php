<?php

namespace App\Notifications;

use App\Models\ExtensionRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ExtensionApprovedNotification extends Notification implements ShouldQueue
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
            ->subject('تمت الموافقة على طلب التمديد | Extension Approved')
            ->line('Your extension request has been approved.')
            ->line("New date: {$this->extensionRequest->requested_date}");
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'extension_approved',
            'extension_id' => $this->extensionRequest->id,
            'message_ar' => 'تمت الموافقة على طلب التمديد الخاص بك',
            'message_en' => 'Your extension request has been approved',
        ];
    }
}
