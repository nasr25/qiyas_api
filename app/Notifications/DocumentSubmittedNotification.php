<?php

namespace App\Notifications;

use App\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifies auditors when a document is submitted for review.
 */
class DocumentSubmittedNotification extends Notification implements ShouldQueue
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
            ->subject('وثيقة جديدة بانتظار المراجعة | New Document Pending Review')
            ->line("Department: {$this->document->department?->name_en}")
            ->line("Document: {$this->document->title}")
            ->action('Review Document', url("/auditor/documents/{$this->document->id}"))
            ->line('Please review and take action.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'          => 'document_submitted',
            'document_id'   => $this->document->id,
            'title'         => $this->document->title,
            'department'    => $this->document->department?->name_ar,
            'message_ar'    => "وثيقة جديدة بانتظار مراجعتك: {$this->document->title}",
            'message_en'    => "New document pending review: {$this->document->title}",
        ];
    }
}
