<?php

namespace App\Services;

use App\Events\WorkflowNotificationRequested;
use App\Exceptions\WorkflowConflictException;
use App\Models\ExtensionRequest;
use App\Models\RequirementAssignment;
use App\Models\User;
use App\Models\WorkflowEvent;
use Illuminate\Support\Facades\DB;

/**
 * The reviewer role (who decides an extension request) is program-scoped
 * configuration as of Phase 4 (category 'extensions', key 'reviewer_role')
 * — see docs/extension-engine.md and ExtensionRequestPolicy::decide(). For
 * Qiyas that role is 'auditor', so the Department Manager still never
 * decides one (Phase 2 requirement, unchanged); approving an extension
 * updates `effective_due_date` only, `original_due_date` is never touched,
 * so historical delay calculations stay accurate. See
 * docs/extension-request-workflow.md.
 */
class ExtensionService
{
    public function __construct(private readonly ProgramConfigurationService $config) {}

    public function request(RequirementAssignment $assignment, User $employee, string $requestedDueDate, string $reason): ExtensionRequest
    {
        return DB::transaction(function () use ($assignment, $employee, $requestedDueDate, $reason) {
            $assignment = RequirementAssignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();

            $alreadyPending = ExtensionRequest::where('requirement_assignment_id', $assignment->id)->pending()->exists();
            if ($alreadyPending) {
                throw new WorkflowConflictException('An extension request is already pending for this requirement.');
            }

            $currentDueDate = $assignment->effective_due_date;
            if ($currentDueDate && strtotime($requestedDueDate) <= $currentDueDate->timestamp) {
                throw new WorkflowConflictException('The requested due date must be later than the current effective due date.');
            }

            $extension = ExtensionRequest::create([
                'requirement_assignment_id' => $assignment->id,
                'compliance_program_id' => $assignment->compliance_program_id,
                'program_cycle_id' => $assignment->program_cycle_id,
                'requested_by' => $employee->id,
                'requested_date' => now()->toDateString(),
                'current_due_date' => $currentDueDate,
                'requested_due_date' => $requestedDueDate,
                'reason' => $reason,
                'status' => ExtensionRequest::STATUS_PENDING,
            ]);

            $this->recordEvent($assignment, 'extension_requested', $employee, 'employee', $reason);
            $this->notify('extension_requested', $assignment, $this->reviewersAndManager($assignment));

            return $extension;
        });
    }

    public function decide(ExtensionRequest $extension, User $auditor, string $decision, ?string $reason, ?string $notes): ExtensionRequest
    {
        return DB::transaction(function () use ($extension, $auditor, $decision, $reason, $notes) {
            $extension = ExtensionRequest::whereKey($extension->id)->lockForUpdate()->firstOrFail();
            if (! $extension->isPending()) {
                throw new WorkflowConflictException('This extension request has already been decided.');
            }
            if ($decision === 'rejected' && ! $reason) {
                throw new WorkflowConflictException('A rejection reason is required.');
            }

            $assignment = RequirementAssignment::whereKey($extension->requirement_assignment_id)->lockForUpdate()->first();

            $extension->update([
                'status' => $decision,
                'reviewed_by' => $auditor->id,
                'reviewed_at' => now(),
                'reviewer_notes' => $decision === 'rejected' ? trim(($reason ?? '').($notes ? " — {$notes}" : '')) : $notes,
            ]);

            if ($decision === 'approved' && $assignment) {
                // Original due date is never overwritten — only the effective one moves.
                $assignment->update(['effective_due_date' => $extension->requested_due_date]);
            }

            if ($assignment) {
                $this->recordEvent($assignment, $decision === 'approved' ? 'extension_approved' : 'extension_rejected', $auditor, 'auditor', $notes ?? $reason);
                $this->notify(
                    $decision === 'approved' ? 'extension_approved' : 'extension_rejected',
                    $assignment,
                    array_filter([$extension->requester, $assignment->department?->users()->where('is_active', true)->first()]),
                );
            }

            return $extension->fresh();
        });
    }

    public function cancel(ExtensionRequest $extension, User $actor): ExtensionRequest
    {
        return DB::transaction(function () use ($extension, $actor) {
            $extension = ExtensionRequest::whereKey($extension->id)->lockForUpdate()->firstOrFail();
            if (! $extension->isPending()) {
                throw new WorkflowConflictException('Only a pending extension request can be cancelled.');
            }
            if ($extension->requested_by !== $actor->id && ! $actor->isPlatformSuperAdmin()) {
                throw new WorkflowConflictException('Only the requester can cancel this extension request.');
            }

            $extension->update(['status' => ExtensionRequest::STATUS_CANCELLED]);

            return $extension->fresh();
        });
    }

    private function recordEvent(RequirementAssignment $assignment, string $eventType, User $user, string $role, ?string $notes): void
    {
        WorkflowEvent::create([
            'compliance_program_id' => $assignment->compliance_program_id,
            'program_cycle_id' => $assignment->program_cycle_id,
            'requirement_assignment_id' => $assignment->id,
            'event_type' => $eventType,
            'user_id' => $user->id,
            'role' => $role,
            'notes' => $notes,
            'created_at' => now(),
        ]);

        AuditService::log("workflow.{$eventType}", $notes ?? $eventType, $assignment, complianceProgramId: $assignment->compliance_program_id);
    }

    /** Publishes a domain event rather than calling NotificationService directly — see docs/notification-engine.md. */
    private function notify(string $eventType, RequirementAssignment $assignment, array $recipients): void
    {
        event(new WorkflowNotificationRequested($eventType, $assignment, array_filter($recipients)));
    }

    private function reviewersAndManager(RequirementAssignment $assignment): array
    {
        $reviewerRole = $this->config->get($assignment->program, 'extensions', ['reviewer_role' => 'auditor'])['reviewer_role'] ?? 'auditor';

        $reviewers = User::whereHas('programRoles', fn ($q) => $q
            ->where('compliance_program_id', $assignment->compliance_program_id)
            ->where('role_key', $reviewerRole)->where('is_active', true))
            ->where('is_active', true)->get()->all();

        $manager = User::whereHas('programRoles', fn ($q) => $q
            ->where('compliance_program_id', $assignment->compliance_program_id)
            ->where('role_key', 'department-manager')
            ->where('department_id', $assignment->department_id)->where('is_active', true))
            ->where('is_active', true)->get()->all();

        return array_merge($reviewers, $manager);
    }
}
