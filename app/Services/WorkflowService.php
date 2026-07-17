<?php

namespace App\Services;

use App\Exceptions\WorkflowConflictException;
use App\Models\ComplianceProgram;
use App\Models\Department;
use App\Models\EvidenceFile;
use App\Models\EvidenceSubmission;
use App\Models\RequirementAssignment;
use App\Models\Standard;
use App\Models\User;
use App\Models\WorkflowDecision;
use App\Models\WorkflowEvent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The single controlled domain service for the Qiyas operational workflow.
 * No controller sets a RequirementAssignment/EvidenceSubmission status
 * directly — every transition goes through here, wrapped in a transaction
 * with row locking, so two conflicting actions can never both succeed.
 *
 * See docs/qiyas-workflow.md for the full state diagram.
 */
class WorkflowService
{
    /** Stage this stage's approval moves the submission to next; null = final approval. */
    private const NEXT_STAGE = [
        'department_manager' => 'auditor',
        'auditor' => 'program_manager',
        'program_manager' => null,
    ];

    private const STATUS_FOR_STAGE = [
        'department_manager' => 'pending_department_manager',
        'auditor' => 'pending_auditor',
        'program_manager' => 'pending_program_manager',
    ];

    public function __construct(
        private readonly SlaService $sla,
        private readonly NotificationService $notifications,
    ) {}

    // ─── Program Manager: assignment ───────────────────────────────────────

    public function assign(
        Standard $requirement,
        ComplianceProgram $program,
        User $assignedBy,
        Department $department,
        ?User $employee,
        ?string $dueDate,
        ?string $priority,
        ?string $instructionsAr,
        ?string $instructionsEn,
    ): RequirementAssignment {
        return DB::transaction(function () use ($requirement, $program, $assignedBy, $department, $employee, $dueDate, $priority, $instructionsAr, $instructionsEn) {
            $existing = RequirementAssignment::where('requirement_id', $requirement->id)->active()->lockForUpdate()->first();
            if ($existing) {
                throw new WorkflowConflictException('This requirement is already assigned. Use reassignment to change the department.');
            }

            $assignment = RequirementAssignment::create([
                'compliance_program_id' => $program->id,
                'program_cycle_id' => $requirement->cycle_id,
                'requirement_id' => $requirement->id,
                'department_id' => $department->id,
                'employee_id' => $employee?->id,
                'assigned_by' => $assignedBy->id,
                'assigned_at' => now(),
                'original_due_date' => $dueDate,
                'effective_due_date' => $dueDate,
                'status' => 'active',
                'priority' => $priority,
                'instructions_ar' => $instructionsAr,
                'instructions_en' => $instructionsEn,
            ]);

            $this->recordEvent($assignment, 'requirement_assigned', $assignedBy, 'program-manager', null, 'assigned');
            if ($employee) {
                $this->recordEvent($assignment, 'employee_selected', $assignedBy, 'program-manager');
            }

            $this->sla->openInstance($assignment, null, 'employee', $employee, $department);

            $this->notify($assignment, 'requirement_assigned', $this->assignmentRecipients($assignment));

            return $assignment->fresh();
        });
    }

    public function reassignDepartment(RequirementAssignment $assignment, Department $newDepartment, string $reason, User $actor): RequirementAssignment
    {
        return DB::transaction(function () use ($assignment, $newDepartment, $reason, $actor) {
            $assignment = RequirementAssignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();

            if (! $assignment->isActive()) {
                throw new WorkflowConflictException('Only an active assignment can be reassigned.');
            }
            if ($assignment->department_id === $newDepartment->id) {
                throw new WorkflowConflictException('The requirement is already assigned to this department.');
            }

            $oldDepartmentId = $assignment->department_id;

            $assignment->update(['status' => 'reassigned']);
            $this->sla->closeActiveInstance($assignment, 'employee', 'cancelled');

            $new = RequirementAssignment::create([
                'compliance_program_id' => $assignment->compliance_program_id,
                'program_cycle_id' => $assignment->program_cycle_id,
                'requirement_id' => $assignment->requirement_id,
                'department_id' => $newDepartment->id,
                'employee_id' => null,
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
                'original_due_date' => $assignment->original_due_date,
                'effective_due_date' => $assignment->effective_due_date,
                'status' => 'active',
                'priority' => $assignment->priority,
                'instructions_ar' => $assignment->instructions_ar,
                'instructions_en' => $assignment->instructions_en,
                'previous_assignment_id' => $assignment->id,
                'reassignment_reason' => $reason,
            ]);

            $this->recordEvent($assignment, 'department_reassigned', $actor, 'program-manager', 'active', 'reassigned', $reason, [
                'old_department_id' => $oldDepartmentId, 'new_department_id' => $newDepartment->id,
            ]);
            $this->sla->openInstance($new, null, 'employee', null, $newDepartment);
            $this->notify($new, 'requirement_reassigned', $this->assignmentRecipients($new));

            return $new->fresh();
        });
    }

    public function updateAssignmentDetails(RequirementAssignment $assignment, array $data, User $actor): RequirementAssignment
    {
        return DB::transaction(function () use ($assignment, $data, $actor) {
            $assignment = RequirementAssignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            if (! $assignment->isActive()) {
                throw new WorkflowConflictException('Only an active assignment can be updated.');
            }

            $dueDateChanged = array_key_exists('effective_due_date', $data)
                && (string) $assignment->effective_due_date?->toDateString() !== (string) $data['effective_due_date'];
            $employeeChanged = array_key_exists('employee_id', $data) && $assignment->employee_id !== $data['employee_id'];

            $assignment->update(array_intersect_key($data, array_flip([
                'effective_due_date', 'employee_id', 'priority', 'instructions_ar', 'instructions_en',
            ])));

            if ($dueDateChanged) {
                $this->recordEvent($assignment, 'due_date_changed', $actor, null, null, null, null, ['effective_due_date' => $data['effective_due_date']]);
            }
            if ($employeeChanged) {
                $this->recordEvent($assignment, 'employee_reassigned', $actor, null, null, null, null, ['employee_id' => $data['employee_id']]);
                $this->notify($assignment, 'employee_reassigned', $this->assignmentRecipients($assignment));
            }

            return $assignment->fresh();
        });
    }

    // ─── Employee: draft / upload / submit ─────────────────────────────────

    public function getOrCreateDraft(RequirementAssignment $assignment, User $employee): EvidenceSubmission
    {
        return DB::transaction(function () use ($assignment, $employee) {
            $assignment = RequirementAssignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();

            $current = $assignment->submissions()->orderByDesc('version_number')->first();

            if ($current && in_array($current->status, ['draft', 'returned_for_revision'], true)) {
                if ($current->status === 'returned_for_revision') {
                    // A fresh editable draft version starts on first edit after return.
                    return $this->startNewVersion($assignment, $current, $employee);
                }

                return $current;
            }

            if ($current) {
                // Currently pending review or approved — not editable; return read-only reference.
                return $current;
            }

            $version = EvidenceSubmission::create([
                'compliance_program_id' => $assignment->compliance_program_id,
                'program_cycle_id' => $assignment->program_cycle_id,
                'requirement_id' => $assignment->requirement_id,
                'requirement_assignment_id' => $assignment->id,
                'department_id' => $assignment->department_id,
                'submitted_by' => $employee->id,
                'version_number' => 1,
                'status' => 'draft',
                'current_stage' => 'employee',
            ]);

            $this->recordEvent($assignment, 'draft_created', $employee, 'employee', null, 'draft', null, null, $version);

            return $version;
        });
    }

    private function startNewVersion(RequirementAssignment $assignment, EvidenceSubmission $previous, User $employee): EvidenceSubmission
    {
        $version = EvidenceSubmission::create([
            'compliance_program_id' => $assignment->compliance_program_id,
            'program_cycle_id' => $assignment->program_cycle_id,
            'requirement_id' => $assignment->requirement_id,
            'requirement_assignment_id' => $assignment->id,
            'department_id' => $assignment->department_id,
            'submitted_by' => $employee->id,
            'version_number' => $previous->version_number + 1,
            'status' => 'draft',
            'current_stage' => 'employee',
        ]);

        $this->recordEvent($assignment, 'draft_created', $employee, 'employee', 'returned_for_revision', 'draft', null, null, $version);

        return $version;
    }

    public function addFile(EvidenceSubmission $submission, UploadedFile $file, User $employee): EvidenceFile
    {
        return DB::transaction(function () use ($submission, $file, $employee) {
            $submission = EvidenceSubmission::whereKey($submission->id)->lockForUpdate()->firstOrFail();
            if (! $submission->isEditableByEmployee()) {
                throw new WorkflowConflictException('Files can only be added while the submission is a draft.');
            }

            $storedName = Str::uuid()->toString().'.'.strtolower($file->getClientOriginalExtension());
            $directory = "evidence/{$submission->compliance_program_id}/{$submission->requirement_assignment_id}/{$submission->id}";
            $path = $file->storeAs($directory, $storedName, 'private');

            $evidenceFile = EvidenceFile::create([
                'evidence_submission_id' => $submission->id,
                'original_name' => $file->getClientOriginalName(),
                'stored_name' => $storedName,
                'storage_path' => $path,
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'file_hash' => hash_file('sha256', $file->getRealPath()),
                'uploaded_by' => $employee->id,
                'uploaded_at' => now(),
                'is_active' => true,
            ]);

            AuditService::log('evidence.uploaded', "Uploaded {$file->getClientOriginalName()} for submission #{$submission->id}", $submission, complianceProgramId: $submission->compliance_program_id);
            $this->recordEvent($submission->assignment, 'evidence_uploaded', $employee, 'employee', null, null, null, ['file' => $file->getClientOriginalName()], $submission);

            return $evidenceFile;
        });
    }

    public function removeFile(EvidenceFile $file, User $employee): void
    {
        DB::transaction(function () use ($file, $employee) {
            $submission = EvidenceSubmission::whereKey($file->evidence_submission_id)->lockForUpdate()->firstOrFail();
            if (! $submission->isEditableByEmployee()) {
                throw new WorkflowConflictException('Files can only be removed while the submission is a draft.');
            }

            $file->update(['is_active' => false]);
            if (Storage::disk('private')->exists($file->storage_path)) {
                Storage::disk('private')->delete($file->storage_path);
            }

            AuditService::log('evidence.file_removed', "Removed {$file->original_name} from submission #{$submission->id}", $submission, complianceProgramId: $submission->compliance_program_id);
            $this->recordEvent($submission->assignment, 'evidence_removed', $employee, 'employee', null, null, null, ['file' => $file->original_name], $submission);
        });
    }

    public function submit(EvidenceSubmission $submission, User $employee, ?string $comment): EvidenceSubmission
    {
        return DB::transaction(function () use ($submission, $employee, $comment) {
            $submission = EvidenceSubmission::whereKey($submission->id)->lockForUpdate()->firstOrFail();
            if (! $submission->isEditableByEmployee()) {
                throw new WorkflowConflictException('Only a draft or returned submission can be submitted.');
            }
            if ($submission->activeFiles()->count() === 0) {
                throw new WorkflowConflictException('At least one evidence file is required before submitting.');
            }

            $assignment = RequirementAssignment::whereKey($submission->requirement_assignment_id)->lockForUpdate()->firstOrFail();

            $oldStatus = $submission->status;
            $submission->update([
                'status' => 'pending_department_manager',
                'current_stage' => 'department_manager',
                'employee_comment' => $comment,
                'submitted_at' => now(),
                'returned_at' => null,
            ]);

            $this->sla->closeActiveInstance($assignment, 'employee');
            $this->sla->openInstance($assignment, $submission, 'department_manager', null, $assignment->department);

            $this->recordEvent($assignment, $oldStatus === 'returned_for_revision' ? 'resubmitted' : 'submitted_to_department_manager', $employee, 'employee', $oldStatus, 'pending_department_manager', null, null, $submission);
            $this->notify($assignment, 'submission_sent_to_department_manager', $this->departmentManagerRecipients($assignment));

            return $submission->fresh();
        });
    }

    // ─── Reviewers: department manager / auditor / program manager ────────

    public function approve(EvidenceSubmission $submission, User $reviewer, string $stage, string $reviewerRole, ?string $notes): EvidenceSubmission
    {
        return $this->decide($submission, $reviewer, $stage, $reviewerRole, 'approved', $notes, null);
    }

    public function reject(EvidenceSubmission $submission, User $reviewer, string $stage, string $reviewerRole, string $reason, ?string $notes): EvidenceSubmission
    {
        return $this->decide($submission, $reviewer, $stage, $reviewerRole, 'rejected', $notes, $reason);
    }

    private function decide(
        EvidenceSubmission $submission,
        User $reviewer,
        string $stage,
        string $reviewerRole,
        string $decision,
        ?string $notes,
        ?string $rejectionReason,
    ): EvidenceSubmission {
        return DB::transaction(function () use ($submission, $reviewer, $stage, $reviewerRole, $decision, $notes, $rejectionReason) {
            $submission = EvidenceSubmission::whereKey($submission->id)->lockForUpdate()->firstOrFail();
            $expectedStatus = self::STATUS_FOR_STAGE[$stage] ?? null;

            if (! $expectedStatus || $submission->status !== $expectedStatus) {
                throw new WorkflowConflictException("This submission is not currently pending {$stage} review — it may have already been decided.");
            }

            $assignment = RequirementAssignment::whereKey($submission->requirement_assignment_id)->lockForUpdate()->firstOrFail();

            WorkflowDecision::create([
                'compliance_program_id' => $submission->compliance_program_id,
                'program_cycle_id' => $submission->program_cycle_id,
                'evidence_submission_id' => $submission->id,
                'stage' => $stage,
                'decision' => $decision,
                'reviewer_id' => $reviewer->id,
                'reviewer_role' => $reviewerRole,
                'notes' => $notes,
                'rejection_reason' => $rejectionReason,
                'decided_at' => now(),
            ]);

            $oldStatus = $submission->status;

            if ($decision === 'rejected') {
                $submission->update([
                    'status' => 'returned_for_revision',
                    'current_stage' => 'employee',
                    'returned_at' => now(),
                ]);
                $this->sla->closeActiveInstance($assignment, $stage);
                $this->sla->openInstance($assignment, $submission, 'employee', $assignment->employee, $assignment->department);

                $this->recordEvent($assignment, "{$this->eventStageLabel($stage)}_rejected", $reviewer, $reviewerRole, $oldStatus, 'returned_for_revision', $rejectionReason, null, $submission);
                $this->notify($assignment, "{$stage}_rejected", $this->employeeRecipients($assignment));

                return $submission->fresh();
            }

            $nextStage = self::NEXT_STAGE[$stage];
            $this->sla->closeActiveInstance($assignment, $stage);

            if ($nextStage === null) {
                // Final approval.
                $submission->update([
                    'status' => 'approved',
                    'current_stage' => 'completed',
                    'approved_at' => now(),
                ]);
                $assignment->update(['status' => 'completed', 'completed_at' => now()]);

                $this->recordEvent($assignment, 'program_manager_approved', $reviewer, $reviewerRole, $oldStatus, 'approved', $notes, null, $submission);
                $this->recordEvent($assignment, 'final_approval_completed', $reviewer, $reviewerRole, null, 'approved', null, null, $submission);
                $this->notify($assignment, 'program_manager_approved', $this->allStakeholderRecipients($assignment));

                return $submission->fresh();
            }

            $nextStatus = self::STATUS_FOR_STAGE[$nextStage];
            $submission->update(['status' => $nextStatus, 'current_stage' => $nextStage]);

            $responsibleUser = $nextStage === 'department_manager' ? null : null; // resolved per-department/program at review time, not a single fixed user
            $this->sla->openInstance($assignment, $submission, $nextStage, $responsibleUser, $assignment->department);

            $this->recordEvent($assignment, "{$this->eventStageLabel($stage)}_approved", $reviewer, $reviewerRole, $oldStatus, $nextStatus, $notes, null, $submission);
            $this->notify($assignment, "submission_sent_to_{$nextStage}", $this->stageRecipients($assignment, $nextStage));

            return $submission->fresh();
        });
    }

    private function eventStageLabel(string $stage): string
    {
        return match ($stage) {
            'department_manager' => 'department_manager',
            'auditor' => 'auditor',
            'program_manager' => 'program_manager',
            default => $stage,
        };
    }

    // ─── Event / notification helpers ──────────────────────────────────────

    /**
     * Writes both the domain timeline row (WorkflowEvent, shown to users on
     * the requirement's history tab) and the platform audit log
     * (AuditService, shown to Super Admin/Auditor on the audit log page) —
     * every workflow transition is captured in both places from this one
     * method, so no call site can accidentally skip auditing.
     */
    private function recordEvent(
        RequirementAssignment $assignment,
        string $eventType,
        ?User $user,
        ?string $role,
        ?string $oldStatus = null,
        ?string $newStatus = null,
        ?string $notes = null,
        ?array $metadata = null,
        ?EvidenceSubmission $submission = null,
    ): WorkflowEvent {
        $event = WorkflowEvent::create([
            'compliance_program_id' => $assignment->compliance_program_id,
            'program_cycle_id' => $assignment->program_cycle_id,
            'requirement_assignment_id' => $assignment->id,
            'evidence_submission_id' => $submission?->id,
            'event_type' => $eventType,
            'user_id' => $user?->id,
            'role' => $role,
            'notes' => $notes,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'evidence_version' => $submission?->version_number,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);

        AuditService::log(
            "workflow.{$eventType}",
            $notes ? "{$eventType} — {$notes}" : $eventType,
            $submission ?? $assignment,
            $oldStatus ? ['status' => $oldStatus] : null,
            $newStatus ? ['status' => $newStatus] : null,
            complianceProgramId: $assignment->compliance_program_id,
        );

        return $event;
    }

    /** Queues a deduplicated notification for each recipient — see NotificationService. */
    private function notify(RequirementAssignment $assignment, string $eventType, iterable $recipients): void
    {
        $this->notifications->dispatchForAssignment($eventType, $assignment, array_filter($recipients));
    }

    private function assignmentRecipients(RequirementAssignment $assignment): array
    {
        $recipients = $assignment->department->users()->where('is_active', true)->get()->all();
        if ($assignment->employee) {
            $recipients[] = $assignment->employee;
        }

        return $recipients;
    }

    private function employeeRecipients(RequirementAssignment $assignment): array
    {
        return array_filter([$assignment->employee]);
    }

    private function departmentManagerRecipients(RequirementAssignment $assignment): array
    {
        return User::whereHas('programRoles', function ($q) use ($assignment) {
            $q->where('compliance_program_id', $assignment->compliance_program_id)
                ->where('role_key', 'department-manager')
                ->where('department_id', $assignment->department_id)
                ->where('is_active', true);
        })->where('is_active', true)->get()->all();
    }

    private function stageRecipients(RequirementAssignment $assignment, string $stage): array
    {
        return match ($stage) {
            'auditor' => User::whereHas('programRoles', fn ($q) => $q
                ->where('compliance_program_id', $assignment->compliance_program_id)
                ->where('role_key', 'auditor')->where('is_active', true))
                ->where('is_active', true)->get()->all(),
            'program_manager' => User::whereHas('programRoles', fn ($q) => $q
                ->where('compliance_program_id', $assignment->compliance_program_id)
                ->where('role_key', 'program-manager')->where('is_active', true))
                ->where('is_active', true)->get()->all(),
            default => [],
        };
    }

    private function allStakeholderRecipients(RequirementAssignment $assignment): array
    {
        return array_merge($this->employeeRecipients($assignment), $this->departmentManagerRecipients($assignment));
    }
}
