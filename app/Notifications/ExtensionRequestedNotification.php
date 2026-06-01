<?php

namespace App\Notifications;

use App\Models\ExtensionRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ExtensionRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly ExtensionRequest $extensionRequest) {}

    public function via(object $notifiable): array { return ['database', 'mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('طلب تمديد جديد | New Extension Request')
            ->line("Extension requested for: {$this->extensionRequest->document?->title}")
            ->line("Requested date: {$this->extensionRequest->requested_date}")
            ->action('Review Request', url("/auditor/extensions/{$this->extensionRequest->id}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'            => 'extension_requested',
            'extension_id'    => $this->extensionRequest->id,
            'document_title'  => $this->extensionRequest->document?->title,
            'message_ar'      => 'طلب تمديد جديد بانتظار مراجعتك',
            'message_en'      => 'New extension request pending your review',
        ];
    }
}
