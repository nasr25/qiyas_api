<?php

namespace App\Services;

use App\Models\ComplianceProgram;
use App\Models\NotificationLog;
use App\Models\RequirementAssignment;
use App\Models\User;
use App\Notifications\WorkflowEventNotification;

/**
 * Central dispatch point for every workflow notification. Deduplicates via
 * a deterministic idempotency key (event_type + assignment + submission
 * version + recipient), so the same event can never queue twice for the
 * same recipient even if a controller action is retried. See
 * docs/email-notifications.md.
 */
class NotificationService
{
    /**
     * Dispatches one notification per recipient for an assignment-scoped
     * event. Safe to call multiple times for the same logical event — only
     * the first call per recipient actually queues anything.
     */
    public function dispatchForAssignment(string $eventType, RequirementAssignment $assignment, iterable $recipients): void
    {
        $submissionVersion = $assignment->relationLoaded('currentSubmission')
            ? $assignment->currentSubmission?->version_number
            : $assignment->currentSubmission()->value('version_number');

        foreach ($recipients as $recipient) {
            if (! $recipient instanceof User || ! $recipient->email) {
                continue;
            }

            $this->dispatchOnce(
                eventType: $eventType,
                idempotencyKey: $this->buildKey($eventType, $assignment->id, $submissionVersion, $recipient->id),
                recipient: $recipient,
                program: $assignment->program,
                notification: new WorkflowEventNotification($eventType, $assignment),
            );
        }
    }

    /**
     * Dispatches a single event not tied to a RequirementAssignment (e.g. a
     * standalone extension-request or SLA event that already has its own
     * uniqueness scope in $idempotencyKey).
     */
    public function dispatchOnce(
        string $eventType,
        string $idempotencyKey,
        User $recipient,
        ?ComplianceProgram $program,
        mixed $notification,
    ): void {
        $log = NotificationLog::firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'template_key' => $eventType,
                'event_type' => $eventType,
                'recipient_user_id' => $recipient->id,
                'recipient_email' => $recipient->email,
                'compliance_program_id' => $program?->id,
                'subject' => $eventType,
                'status' => 'queued',
            ],
        );

        if (! $log->wasRecentlyCreated) {
            return; // Already dispatched for this recipient/event — do not send again.
        }

        $recipient->notify($notification);
    }

    private function buildKey(string $eventType, int $assignmentId, ?int $submissionVersion, int $recipientId): string
    {
        return sprintf('%s:assignment:%d:v%s:user:%d', $eventType, $assignmentId, $submissionVersion ?? '0', $recipientId);
    }
}
