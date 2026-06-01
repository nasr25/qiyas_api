<?php

namespace App\Notifications;

use App\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DocumentRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Document $document,
        private readonly string   $reason
    ) {}

    public function via(object $notifiable): array { return ['database', 'mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('تم رفض الوثيقة | Document Rejected')
            ->line("Document '{$this->document->title}' has been rejected.")
            ->line("Reason: {$this->reason}")
            ->action('View Document', url("/documents/{$this->document->id}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'        => 'document_rejected',
            'document_id' => $this->document->id,
            'title'       => $this->document->title,
            'reason'      => $this->reason,
            'message_ar'  => "تم رفض الوثيقة: {$this->document->title}",
            'message_en'  => "Document rejected: {$this->document->title}",
        ];
    }
}
