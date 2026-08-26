<?php

namespace App\Console\Commands;

use App\Models\ComplianceProgram;
use App\Models\EvidenceFile;
use App\Models\EvidenceSubmission;
use App\Models\ExtensionRequest;
use App\Models\ImportLog;
use App\Models\NotificationLog;
use App\Models\RequirementAssignment;
use App\Models\SlaInstance;
use App\Models\Standard;
use App\Models\WorkflowDecision;
use Illuminate\Console\Command;

/**
 * Read-only Phase 2 (Qiyas operational workflow) data-integrity report.
 * Never writes to the database. Complements `compliance:verify-migration`
 * (Phase 1 structural checks) with the workflow-specific relationship and
 * state-consistency checks called for in the Phase 3 audit. See
 * docs/qiyas-data-integrity.md.
 */
class VerifyQiyasDataIntegrity extends Command
{
    protected $signature = 'compliance:verify-qiyas';

    protected $description = 'Read-only data-integrity report for the Qiyas Phase 2 operational workflow.';

    public function handle(): int
    {
        $this->info('Qiyas Phase 2 data-integrity verification');
        $this->line('(read-only — no data is modified by this command)');
        $this->newLine();

        $program = ComplianceProgram::where('code', 'QIYAS')->first();
        if (! $program) {
            $this->error('No QIYAS program found — nothing to verify.');

            return self::FAILURE;
        }

        $this->table(['Metric', 'Count'], [
            ['Qiyas program existence', 1],
            ['Active Qiyas cycles', $program->cycles()->where('is_current', true)->count()],
            ['Perspectives (distinct)', Standard::where('compliance_program_id', $program->id)->whereNotNull('perspective')->distinct('perspective')->count('perspective')],
            ['Axes (distinct)', Standard::where('compliance_program_id', $program->id)->whereNotNull('axis')->distinct('axis')->count('axis')],
            ['Standards', Standard::where('compliance_program_id', $program->id)->count()],
            ['Requirement assignments', RequirementAssignment::where('compliance_program_id', $program->id)->count()],
            ['Evidence submissions', EvidenceSubmission::where('compliance_program_id', $program->id)->count()],
            ['Evidence files', EvidenceFile::whereHas('submission', fn ($q) => $q->where('compliance_program_id', $program->id))->count()],
            ['Review (workflow) decisions', WorkflowDecision::where('compliance_program_id', $program->id)->count()],
            ['Extension requests', ExtensionRequest::where('compliance_program_id', $program->id)->count()],
            ['SLA instances', SlaInstance::where('compliance_program_id', $program->id)->count()],
            ['Notification deliveries', NotificationLog::where('compliance_program_id', $program->id)->count()],
            ['Import logs', ImportLog::where('compliance_program_id', $program->id)->count()],
        ]);

        $this->newLine();
        $this->comment('Integrity checks:');

        $checks = $this->runChecks($program);

        foreach ($checks as [$label, $count, $ok]) {
            $icon = $ok ? '<fg=green>OK</>' : '<fg=red>FAIL</>';
            $this->line(sprintf('  [%s] %-65s %d', $icon, $label, $count));
        }

        $failed = collect($checks)->contains(fn ($c) => ! $c[2]);

        $this->newLine();
        if ($failed) {
            $this->error('One or more integrity checks failed. Review the counts above before treating Qiyas data as production-ready.');

            return self::FAILURE;
        }

        $this->info('All Qiyas data-integrity checks passed.');

        return self::SUCCESS;
    }

    /** @return array<int, array{0: string, 1: int, 2: bool}> */
    private function runChecks(ComplianceProgram $program): array
    {
        $programId = $program->id;

        // Orphan / dangling-relationship checks.
        $assignmentsBadDept = RequirementAssignment::where('compliance_program_id', $programId)
            ->whereDoesntHave('department')->count();
        $assignmentsBadRequirement = RequirementAssignment::where('compliance_program_id', $programId)
            ->whereDoesntHave('requirement')->count();
        $submissionsWithoutAssignment = EvidenceSubmission::where('compliance_program_id', $programId)
            ->whereDoesntHave('assignment')->count();
        $filesWithoutSubmission = EvidenceFile::whereDoesntHave('submission')->count();
        $decisionsWithoutSubmission = WorkflowDecision::where('compliance_program_id', $programId)
            ->whereDoesntHave('submission')->count();

        // Duplicate-active-assignment check: at most one 'active' assignment
        // per requirement is the invariant WorkflowService::assign() enforces
        // via row locking — this independently confirms no race ever slipped
        // two through.
        $duplicateActiveAssignments = RequirementAssignment::where('compliance_program_id', $programId)
            ->where('status', 'active')
            ->selectRaw('compliance_node_id, COUNT(*) as c')
            ->groupBy('compliance_node_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()->count();

        // Duplicate-pending-extension check: ExtensionService allows only one
        // pending request per assignment at a time.
        $duplicatePendingExtensions = ExtensionRequest::where('compliance_program_id', $programId)
            ->where('status', ExtensionRequest::STATUS_PENDING)
            ->selectRaw('requirement_assignment_id, COUNT(*) as c')
            ->whereNotNull('requirement_assignment_id')
            ->groupBy('requirement_assignment_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()->count();

        // Duplicate-active-SLA-instance check: at most one active instance
        // per (assignment, stage) — WorkflowService always closes the prior
        // instance in the same transaction before opening the next.
        $duplicateActiveSla = SlaInstance::where('compliance_program_id', $programId)
            ->where('status', 'active')
            ->selectRaw('requirement_assignment_id, stage, COUNT(*) as c')
            ->groupBy('requirement_assignment_id', 'stage')
            ->havingRaw('COUNT(*) > 1')
            ->get()->count();

        // Active SLA instance left open on an assignment that is already
        // completed (approved) — would indicate closeActiveInstance() was
        // skipped somewhere on the final-approval path.
        $activeSlaOnCompletedAssignment = SlaInstance::where('compliance_program_id', $programId)
            ->where('status', 'active')
            ->whereHas('assignment', fn ($q) => $q->where('status', 'completed'))
            ->count();

        // An approved submission must have exactly one program_manager
        // 'approved' decision recorded — the final-approval event.
        $approvedWithoutFinalDecision = EvidenceSubmission::where('compliance_program_id', $programId)
            ->where('status', 'approved')
            ->whereDoesntHave('decisions', fn ($q) => $q->where('stage', 'program_manager')->where('decision', 'approved'))
            ->count();

        // A returned-for-revision submission must have at least one
        // 'rejected' decision recorded — reject() always writes one before
        // changing status.
        $returnedWithoutRejection = EvidenceSubmission::where('compliance_program_id', $programId)
            ->where('status', 'returned_for_revision')
            ->whereDoesntHave('decisions', fn ($q) => $q->where('decision', 'rejected'))
            ->count();

        // An approved extension must have moved the assignment's effective
        // due date to (or past) the requested date — decide() always updates
        // effective_due_date on approval.
        $approvedExtensionsWithoutEffectiveDate = ExtensionRequest::where('compliance_program_id', $programId)
            ->where('status', ExtensionRequest::STATUS_APPROVED)
            ->whereHas('assignment', fn ($q) => $q->whereColumn('effective_due_date', '<', 'extension_requests.requested_due_date'))
            ->count();

        // Every submission's status must be one of the known workflow
        // statuses — catches any write that bypassed WorkflowService.
        $submissionsWithImpossibleStatus = EvidenceSubmission::where('compliance_program_id', $programId)
            ->whereNotIn('status', EvidenceSubmission::STATUSES)
            ->count();

        // Bilingual-completeness: every Standard must have an Arabic name,
        // since the platform is Arabic-first.
        $standardsMissingArabicName = Standard::where('compliance_program_id', $programId)
            ->where(fn ($q) => $q->whereNull('name_ar')->orWhere('name_ar', ''))
            ->count();

        // Program-context completeness on every Phase 2 table.
        $submissionsMissingProgram = EvidenceSubmission::whereNull('compliance_program_id')->count();
        $assignmentsMissingProgram = RequirementAssignment::whereNull('compliance_program_id')->count();
        $slaMissingProgram = SlaInstance::whereNull('compliance_program_id')->count();

        return [
            ['Assignments with dangling department_id', $assignmentsBadDept, $assignmentsBadDept === 0],
            ['Assignments with dangling compliance_node_id', $assignmentsBadRequirement, $assignmentsBadRequirement === 0],
            ['Submissions without a parent assignment', $submissionsWithoutAssignment, $submissionsWithoutAssignment === 0],
            ['Evidence files without a parent submission', $filesWithoutSubmission, $filesWithoutSubmission === 0],
            ['Decisions without a parent submission', $decisionsWithoutSubmission, $decisionsWithoutSubmission === 0],
            ['Duplicate active assignments for the same requirement', $duplicateActiveAssignments, $duplicateActiveAssignments === 0],
            ['Duplicate pending extension requests for the same assignment', $duplicatePendingExtensions, $duplicatePendingExtensions === 0],
            ['Duplicate active SLA instances for the same assignment+stage', $duplicateActiveSla, $duplicateActiveSla === 0],
            ['Active SLA instances left open on a completed assignment', $activeSlaOnCompletedAssignment, $activeSlaOnCompletedAssignment === 0],
            ['Approved submissions without a final Program Manager decision', $approvedWithoutFinalDecision, $approvedWithoutFinalDecision === 0],
            ['Returned-for-revision submissions without a rejection decision', $returnedWithoutRejection, $returnedWithoutRejection === 0],
            ['Approved extensions whose effective due date was not advanced', $approvedExtensionsWithoutEffectiveDate, $approvedExtensionsWithoutEffectiveDate === 0],
            ['Submissions with a status outside the known workflow states', $submissionsWithImpossibleStatus, $submissionsWithImpossibleStatus === 0],
            ['Standards missing an Arabic name', $standardsMissingArabicName, $standardsMissingArabicName === 0],
            ['Submissions missing compliance_program_id', $submissionsMissingProgram, $submissionsMissingProgram === 0],
            ['Assignments missing compliance_program_id', $assignmentsMissingProgram, $assignmentsMissingProgram === 0],
            ['SLA instances missing compliance_program_id', $slaMissingProgram, $slaMissingProgram === 0],
        ];
    }
}
