<?php

namespace App\Notifications;

use App\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DocumentApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Document $document) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('تمت الموافقة على الوثيقة | Document Approved')
            ->line("Document '{$this->document->title}' has been approved.")
            ->action('View Document', url("/documents/{$this->document->id}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'document_approved',
            'document_id' => $this->document->id,
            'title' => $this->document->title,
            'message_ar' => "تمت الموافقة على الوثيقة: {$this->document->title}",
            'message_en' => "Document approved: {$this->document->title}",
        ];
    }
}
