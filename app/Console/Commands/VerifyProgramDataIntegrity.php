<?php

namespace App\Console\Commands;

use App\Models\ComplianceContentVersion;
use App\Models\ComplianceNode;
use App\Models\ComplianceProgram;
use App\Models\EvidenceFile;
use App\Models\EvidenceSubmission;
use App\Models\ExtensionRequest;
use App\Models\ImportLog;
use App\Models\NotificationLog;
use App\Models\ProgramConfiguration;
use App\Models\RequirementAssignment;
use App\Models\SlaInstance;
use App\Models\Standard;
use App\Models\WorkflowDecision;
use App\Models\WorkflowDefinition;
use Illuminate\Console\Command;

/**
 * Generic, read-only, program-scoped data-integrity report — the same
 * checks VerifyQiyasDataIntegrity (`compliance:verify-qiyas`) runs for
 * Qiyas specifically, generalized to accept any program code so it works
 * for Sumoud (or any future program) without a copy per program.
 * `compliance:verify-qiyas` is left as-is (unchanged, still passing) to
 * avoid any regression risk to an existing, relied-upon command; the
 * duplication between the two is a deliberate, documented tradeoff — see
 * docs/programs/sumoud/known-limitations.md.
 *
 * Never writes to the database.
 */
class VerifyProgramDataIntegrity extends Command
{
    protected $signature = 'compliance:verify-program {code : Program code, e.g. SUMOUD}';

    protected $description = 'Read-only data-integrity report for any compliance program by code.';

    public function handle(): int
    {
        $code = strtoupper((string) $this->argument('code'));
        $this->info("Program data-integrity verification: {$code}");
        $this->line('(read-only — no data is modified by this command)');
        $this->newLine();

        $program = ComplianceProgram::where('code', $code)->first();
        if (! $program) {
            $this->error("No program with code '{$code}' found.");

            return self::FAILURE;
        }

        $programId = $program->id;

        $this->table(['Metric', 'Count'], [
            ['Program existence', 1],
            ['Configuration status (categories configured)', ProgramConfiguration::where('compliance_program_id', $programId)->count()],
            ['Workflow definition present', WorkflowDefinition::where('compliance_program_id', $programId)->count()],
            ['Active cycles', $program->cycles()->where('is_current', true)->count()],
            ['Content versions', ComplianceContentVersion::where('compliance_program_id', $programId)->count()],
            ['Hierarchy nodes', ComplianceNode::where('compliance_program_id', $programId)->count()],
            ['Assessable hierarchy nodes', ComplianceNode::where('compliance_program_id', $programId)->where('is_assessable', true)->count()],
            ['Domains (perspectives, distinct)', Standard::where('compliance_program_id', $programId)->whereNotNull('perspective')->distinct('perspective')->count('perspective')],
            ['Categories (axes, distinct)', Standard::where('compliance_program_id', $programId)->whereNotNull('axis')->distinct('axis')->count('axis')],
            ['Requirements (standards)', Standard::where('compliance_program_id', $programId)->count()],
            ['Requirement assignments', RequirementAssignment::where('compliance_program_id', $programId)->count()],
            ['Evidence submissions', EvidenceSubmission::where('compliance_program_id', $programId)->count()],
            ['Evidence files', EvidenceFile::whereHas('submission', fn ($q) => $q->where('compliance_program_id', $programId))->count()],
            ['Review (workflow) decisions', WorkflowDecision::where('compliance_program_id', $programId)->count()],
            ['Extension requests', ExtensionRequest::where('compliance_program_id', $programId)->count()],
            ['SLA instances', SlaInstance::where('compliance_program_id', $programId)->count()],
            ['Notification deliveries', NotificationLog::where('compliance_program_id', $programId)->count()],
            ['Import logs', ImportLog::where('compliance_program_id', $programId)->count()],
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
            $this->error("One or more integrity checks failed for {$code}.");

            return self::FAILURE;
        }

        $this->info("All {$code} data-integrity checks passed.");

        return self::SUCCESS;
    }

    /** @return array<int, array{0: string, 1: int, 2: bool}> */
    private function runChecks(ComplianceProgram $program): array
    {
        $programId = $program->id;

        $assignmentsBadDept = RequirementAssignment::where('compliance_program_id', $programId)->whereDoesntHave('department')->count();
        $assignmentsBadRequirement = RequirementAssignment::where('compliance_program_id', $programId)->whereDoesntHave('requirement')->count();
        $submissionsWithoutAssignment = EvidenceSubmission::where('compliance_program_id', $programId)->whereDoesntHave('assignment')->count();
        $decisionsWithoutSubmission = WorkflowDecision::where('compliance_program_id', $programId)->whereDoesntHave('submission')->count();

        $duplicateActiveAssignments = RequirementAssignment::where('compliance_program_id', $programId)
            ->where('status', 'active')->selectRaw('requirement_id, COUNT(*) as c')
            ->groupBy('requirement_id')->havingRaw('COUNT(*) > 1')->get()->count();

        $duplicatePendingExtensions = ExtensionRequest::where('compliance_program_id', $programId)
            ->where('status', ExtensionRequest::STATUS_PENDING)->whereNotNull('requirement_assignment_id')
            ->selectRaw('requirement_assignment_id, COUNT(*) as c')
            ->groupBy('requirement_assignment_id')->havingRaw('COUNT(*) > 1')->get()->count();

        $duplicateActiveSla = SlaInstance::where('compliance_program_id', $programId)
            ->where('status', 'active')->selectRaw('requirement_assignment_id, stage, COUNT(*) as c')
            ->groupBy('requirement_assignment_id', 'stage')->havingRaw('COUNT(*) > 1')->get()->count();

        $activeSlaOnCompletedAssignment = SlaInstance::where('compliance_program_id', $programId)
            ->where('status', 'active')->whereHas('assignment', fn ($q) => $q->where('status', 'completed'))->count();

        $approvedWithoutFinalDecision = EvidenceSubmission::where('compliance_program_id', $programId)
            ->where('status', 'approved')
            ->whereDoesntHave('decisions', fn ($q) => $q->where('stage', 'program_manager')->where('decision', 'approved'))
            ->count();

        $returnedWithoutRejection = EvidenceSubmission::where('compliance_program_id', $programId)
            ->where('status', 'returned_for_revision')
            ->whereDoesntHave('decisions', fn ($q) => $q->where('decision', 'rejected'))
            ->count();

        $submissionsWithImpossibleStatus = EvidenceSubmission::where('compliance_program_id', $programId)
            ->whereNotIn('status', EvidenceSubmission::STATUSES)->count();

        $standardsMissingArabicName = Standard::where('compliance_program_id', $programId)
            ->where(fn ($q) => $q->whereNull('name_ar')->orWhere('name_ar', ''))->count();

        // Cross-program relationship checks — Sumoud (or any program)
        // records must never point at a parent belonging to a different
        // program; see docs/cross-program-isolation.md.
        $standardsWithForeignCycle = Standard::where('compliance_program_id', $programId)
            ->whereHas('cycle', fn ($q) => $q->where('compliance_program_id', '!=', $programId))->count();
        $assignmentsWithForeignRequirement = RequirementAssignment::where('compliance_program_id', $programId)
            ->whereHas('requirement', fn ($q) => $q->where('compliance_program_id', '!=', $programId))->count();
        $submissionsWithForeignAssignment = EvidenceSubmission::where('compliance_program_id', $programId)
            ->whereHas('assignment', fn ($q) => $q->where('compliance_program_id', '!=', $programId))->count();

        // Hierarchy checks (Phase 6, ComplianceNode) — mixed-program parent/
        // child, invalid content-version ownership, circular hierarchy, and
        // assessable nodes missing their bridged `standards` row (the
        // engine correction: an assessable node MUST have standard_id, or
        // it is invisible to the entire assignment/evidence/review pipeline).
        $nodesWithForeignParent = ComplianceNode::where('compliance_program_id', $programId)
            ->whereHas('parent', fn ($q) => $q->where('compliance_program_id', '!=', $programId))->count();
        $nodesWithForeignContentVersion = ComplianceNode::where('compliance_program_id', $programId)
            ->whereHas('contentVersion', fn ($q) => $q->where('compliance_program_id', '!=', $programId))->count();
        $assessableNodesWithoutStandard = ComplianceNode::where('compliance_program_id', $programId)
            ->where('is_assessable', true)->whereNull('standard_id')->count();
        $circularNodes = $this->countCircularNodes($programId);

        return [
            ['Missing program IDs on assignments', RequirementAssignment::whereNull('compliance_program_id')->count(), RequirementAssignment::whereNull('compliance_program_id')->count() === 0],
            ['Hierarchy nodes with a parent in another program', $nodesWithForeignParent, $nodesWithForeignParent === 0],
            ['Hierarchy nodes with a content version in another program', $nodesWithForeignContentVersion, $nodesWithForeignContentVersion === 0],
            ['Assessable hierarchy nodes missing their bridged requirement', $assessableNodesWithoutStandard, $assessableNodesWithoutStandard === 0],
            ['Circular hierarchy references', $circularNodes, $circularNodes === 0],
            ['Assignments with dangling department_id', $assignmentsBadDept, $assignmentsBadDept === 0],
            ['Assignments with dangling requirement_id', $assignmentsBadRequirement, $assignmentsBadRequirement === 0],
            ['Submissions without a parent assignment', $submissionsWithoutAssignment, $submissionsWithoutAssignment === 0],
            ['Decisions without a parent submission', $decisionsWithoutSubmission, $decisionsWithoutSubmission === 0],
            ['Duplicate active assignments for the same requirement', $duplicateActiveAssignments, $duplicateActiveAssignments === 0],
            ['Duplicate pending extension requests for the same assignment', $duplicatePendingExtensions, $duplicatePendingExtensions === 0],
            ['Duplicate active SLA instances for the same assignment+stage', $duplicateActiveSla, $duplicateActiveSla === 0],
            ['Active SLA on completed stages', $activeSlaOnCompletedAssignment, $activeSlaOnCompletedAssignment === 0],
            ['Approved submissions without a final decision', $approvedWithoutFinalDecision, $approvedWithoutFinalDecision === 0],
            ['Returned-for-revision submissions without a rejection decision', $returnedWithoutRejection, $returnedWithoutRejection === 0],
            ['Invalid workflow states (status outside known set)', $submissionsWithImpossibleStatus, $submissionsWithImpossibleStatus === 0],
            ['Standards missing an Arabic name', $standardsMissingArabicName, $standardsMissingArabicName === 0],
            ['Invalid cycle ownership (standard cycle belongs to another program)', $standardsWithForeignCycle, $standardsWithForeignCycle === 0],
            ['Cross-program assignment relationships (requirement in another program)', $assignmentsWithForeignRequirement, $assignmentsWithForeignRequirement === 0],
            ['Cross-program evidence relationships (assignment in another program)', $submissionsWithForeignAssignment, $submissionsWithForeignAssignment === 0],
        ];
    }

    /** Walks each node's ancestor chain (bounded) and counts any that never reach a root — the structural signature of a cycle. */
    private function countCircularNodes(int $programId): int
    {
        $nodes = ComplianceNode::where('compliance_program_id', $programId)->get(['id', 'parent_id'])->keyBy('id');
        $circular = 0;

        foreach ($nodes as $node) {
            $seen = [];
            $current = $node;
            $hops = 0;
            while ($current && $current->parent_id !== null && $hops < 20) {
                if (isset($seen[$current->id])) {
                    $circular++;

                    break;
                }
                $seen[$current->id] = true;
                $current = $nodes->get($current->parent_id);
                $hops++;
            }
        }

        return $circular;
    }
}
