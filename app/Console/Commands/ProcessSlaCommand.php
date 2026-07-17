<?php

namespace App\Console\Commands;

use App\Models\RequirementAssignment;
use App\Models\SlaInstance;
use App\Models\User;
use App\Models\WorkflowEvent;
use App\Notifications\WorkflowEventNotification;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Services\SlaService;
use Illuminate\Console\Command;

/**
 * Scheduled (see routes/console.php): detects SLA warnings/breaches and
 * requirement-delivery overdue conditions, then queues notifications.
 * Every notification is deduplicated via NotificationService's idempotency
 * key, so running this command more often than necessary never produces
 * duplicate emails — see docs/sla-design.md and docs/email-notifications.md.
 *
 * Never depends on a user opening a page: this is the only place SLA
 * breaches and overdue flags are detected and acted upon.
 */
class ProcessSlaCommand extends Command
{
    protected $signature = 'compliance:process-sla';

    protected $description = 'Detect SLA warnings/breaches and overdue requirements; queue deduplicated notifications.';

    public function handle(SlaService $sla, NotificationService $notifications): int
    {
        $warnings = 0;
        $breaches = 0;
        $overdue = 0;

        SlaInstance::active()->whereNotNull('due_at')->with('assignment')->chunkById(200, function ($instances) use ($sla, $notifications, &$warnings, &$breaches) {
            foreach ($instances as $instance) {
                if (! $instance->assignment) {
                    continue;
                }

                if (now()->greaterThan($instance->due_at)) {
                    $sla->markBreached($instance);
                    $this->recordEvent($instance, 'sla_breached');
                    $this->notifyResponsible($notifications, $instance, 'sla_breached');
                    $breaches++;

                    continue;
                }

                $threshold = $instance->settings_snapshot['warning_threshold_percentage'] ?? 80;
                $totalMinutes = max(1, $instance->started_at->diffInMinutes($instance->due_at));
                $elapsedMinutes = $instance->started_at->diffInMinutes(now());
                $percentElapsed = ($elapsedMinutes / $totalMinutes) * 100;

                if ($percentElapsed >= $threshold) {
                    $this->recordEvent($instance, 'sla_warning_generated');
                    $this->notifyResponsible($notifications, $instance, 'sla_warning', dedupeByInstance: true);
                    $warnings++;
                }
            }
        });

        RequirementAssignment::where('status', 'active')
            ->whereNotNull('effective_due_date')
            ->whereDate('effective_due_date', '<', now()->toDateString())
            ->chunkById(200, function ($assignments) use ($notifications, &$overdue) {
                foreach ($assignments as $assignment) {
                    $this->recordEvent($assignment, 'requirement_became_overdue', isAssignmentEvent: true);
                    $recipients = array_filter([$assignment->employee] + $assignment->department->users()->where('is_active', true)->get()->all());
                    $notifications->dispatchOnce(
                        'requirement_overdue',
                        "requirement_overdue:assignment:{$assignment->id}:day:".now()->toDateString(),
                        $recipients[0] ?? $assignment->assignedBy,
                        $assignment->program,
                        new WorkflowEventNotification('requirement_overdue', $assignment),
                    );
                    $overdue++;
                }
            });

        $this->info("SLA processing complete: {$warnings} warnings, {$breaches} breaches, {$overdue} overdue requirements detected.");

        return self::SUCCESS;
    }

    private function recordEvent(SlaInstance|RequirementAssignment $subject, string $eventType, bool $isAssignmentEvent = false): void
    {
        $assignment = $isAssignmentEvent ? $subject : $subject->assignment;

        AuditService::log("workflow.{$eventType}", $eventType, $assignment, complianceProgramId: $assignment->compliance_program_id);

        WorkflowEvent::create([
            'compliance_program_id' => $assignment->compliance_program_id,
            'program_cycle_id' => $assignment->program_cycle_id,
            'requirement_assignment_id' => $assignment->id,
            'evidence_submission_id' => $isAssignmentEvent ? null : $subject->evidence_submission_id,
            'event_type' => $eventType,
            'user_id' => null,
            'role' => 'system',
            'created_at' => now(),
        ]);
    }

    private function notifyResponsible(NotificationService $notifications, SlaInstance $instance, string $eventType, bool $dedupeByInstance = false): void
    {
        $assignment = $instance->assignment;
        $recipient = $instance->responsibleUser ?? $assignment->employee ?? $assignment->assignedBy;
        if (! $recipient) {
            return;
        }

        $key = $dedupeByInstance
            ? "{$eventType}:instance:{$instance->id}"
            : "{$eventType}:instance:{$instance->id}:".now()->toDateString();

        $notifications->dispatchOnce(
            $eventType,
            $key,
            $recipient,
            $assignment->program,
            new WorkflowEventNotification($eventType, $assignment),
        );
    }
}
