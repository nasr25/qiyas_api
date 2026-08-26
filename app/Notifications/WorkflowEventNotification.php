<?php

namespace App\Notifications;

use App\Models\EmailTemplate;
use App\Models\NotificationLog;
use App\Models\RequirementAssignment;
use App\Services\EmailTemplateRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Generic workflow event notification. Renders subject/body from the
 * matching EmailTemplate (template_key === event_type) in the recipient's
 * preferred language. In-app (database) delivery always fires; email only
 * sends when a template exists and is enabled — see
 * docs/email-notifications.md.
 */
class WorkflowEventNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $eventType,
        private readonly RequirementAssignment $assignment,
    ) {}

    public function via(object $notifiable): array
    {
        $template = $this->template();

        return ($template && $template->is_enabled) ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $template = $this->template();
        $locale = $notifiable->locale ?? 'ar';
        $renderer = app(EmailTemplateRenderer::class);
        $variables = $this->variables($notifiable);

        $subject = $template ? $renderer->renderSubject($template, $locale, $variables) : $this->eventType;
        $body = $template ? $renderer->renderBody($template, $locale, $variables) : '';

        $mail = (new MailMessage)->subject($subject)->line($body);
        if (! empty($variables['action_url'])) {
            $mail->action($locale === 'ar' ? 'فتح' : 'Open', $variables['action_url']);
        }

        NotificationLog::where('event_type', $this->eventType)
            ->where('recipient_user_id', $notifiable->id)
            ->latest()->first()?->update(['status' => 'sent', 'sent_at' => now(), 'subject' => $subject]);

        return $mail;
    }

    public function toArray(object $notifiable): array
    {
        $locale = $notifiable->locale ?? 'ar';
        $template = $this->template();
        $variables = $this->variables($notifiable);
        $renderer = app(EmailTemplateRenderer::class);

        return [
            'type' => $this->eventType,
            'requirement_assignment_id' => $this->assignment->id,
            'message_ar' => $template ? $renderer->renderBody($template, 'ar', $variables) : $this->eventType,
            'message_en' => $template ? $renderer->renderBody($template, 'en', $variables) : $this->eventType,
        ];
    }

    private function template(): ?EmailTemplate
    {
        return EmailTemplate::where('template_key', $this->eventType)->first();
    }

    private function variables(object $notifiable): array
    {
        $assignment = $this->assignment;
        $submission = $assignment->relationLoaded('currentSubmission') ? $assignment->currentSubmission : $assignment->currentSubmission()->first();
        $requirement = $assignment->requirement ?? $assignment->requirement()->first();
        $department = $assignment->department ?? $assignment->department()->first();
        $program = $assignment->program ?? $assignment->program()->first();
        $cycle = $assignment->cycle ?? $assignment->cycle()->first();

        return [
            'recipient_name' => $notifiable->name ?? '',
            'employee_name' => $assignment->employee?->name ?? '',
            'department_name' => $department?->name ?? '',
            'program_name' => $program?->name ?? '',
            'cycle_name' => $cycle?->name ?? '',
            'requirement_code' => $requirement?->code ?? '',
            'requirement_name' => $requirement?->name ?? '',
            'current_status' => $submission?->status ?? 'assigned',
            'due_date' => optional($assignment->original_due_date)->toDateString() ?? '',
            'effective_due_date' => optional($assignment->effective_due_date)->toDateString() ?? '',
            'rejection_reason' => $submission?->status === 'returned_for_revision'
                ? (string) $submission->decisions()->latest('decided_at')->value('rejection_reason')
                : '',
            'action_url' => $program ? url("/programs/{$program->code}/requirements/{$requirement?->id}") : '',
        ];
    }
}
