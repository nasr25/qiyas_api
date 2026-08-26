<?php

namespace App\Console\Commands;

use App\Models\AssessmentCycle;
use App\Models\ComplianceNode;
use App\Models\ComplianceProgram;
use App\Models\EvidenceSubmission;
use App\Models\ProgramUserRole;
use App\Models\RequirementAssignment;
use App\Models\Standard;
use Illuminate\Console\Command;

/**
 * Read-only cross-program contamination detector — checks EVERY active
 * program pairwise for records whose parent belongs to a different
 * program than the record itself declares. Complements
 * `compliance:verify-program {code}`, which only checks internal
 * consistency within one program. Never writes to the database. See
 * docs/cross-program-isolation.md.
 */
class VerifyCrossProgramIntegrity extends Command
{
    protected $signature = 'compliance:verify-cross-program';

    protected $description = 'Read-only detector for cross-program data contamination across all compliance programs.';

    public function handle(): int
    {
        $this->info('Cross-program isolation verification');
        $this->line('(read-only — no data is modified by this command)');
        $this->newLine();

        $programs = ComplianceProgram::all();
        $this->line('Programs checked: '.$programs->pluck('code')->implode(', '));
        $this->newLine();

        $standardsForeignCycle = Standard::query()
            ->join('assessment_cycles', 'standards.cycle_id', '=', 'assessment_cycles.id')
            ->whereColumn('standards.compliance_program_id', '!=', 'assessment_cycles.compliance_program_id')
            ->count();

        $assignmentsForeignRequirement = RequirementAssignment::query()
            ->join('compliance_nodes', 'requirement_assignments.compliance_node_id', '=', 'compliance_nodes.id')
            ->whereColumn('requirement_assignments.compliance_program_id', '!=', 'compliance_nodes.compliance_program_id')
            ->count();

        $assignmentsForeignCycle = RequirementAssignment::query()
            ->join('assessment_cycles', 'requirement_assignments.program_cycle_id', '=', 'assessment_cycles.id')
            ->whereColumn('requirement_assignments.compliance_program_id', '!=', 'assessment_cycles.compliance_program_id')
            ->count();

        $submissionsForeignAssignment = EvidenceSubmission::query()
            ->join('requirement_assignments', 'evidence_submissions.requirement_assignment_id', '=', 'requirement_assignments.id')
            ->whereColumn('evidence_submissions.compliance_program_id', '!=', 'requirement_assignments.compliance_program_id')
            ->count();

        // Evidence-file relationship: files belong to a submission, which
        // already carries its own program — a file table with no
        // compliance_program_id column of its own can never itself diverge,
        // so this checks the one real vector: a submission's own cycle
        // must belong to the same program as the submission.
        $submissionsForeignCycle = EvidenceSubmission::query()
            ->join('assessment_cycles', 'evidence_submissions.program_cycle_id', '=', 'assessment_cycles.id')
            ->whereColumn('evidence_submissions.compliance_program_id', '!=', 'assessment_cycles.compliance_program_id')
            ->count();

        // Program membership inconsistency: an active program_user_roles
        // row whose department belongs to no department is fine (global
        // roles), but a row pointing at a nonexistent program is not —
        // guards against an orphaned membership surviving a program's
        // (soft) deletion.
        $membershipsOrphanedProgram = ProgramUserRole::query()
            ->whereNotIn('compliance_program_id', ComplianceProgram::pluck('id'))
            ->count();

        // Phase 6: ComplianceNode (the deep hierarchy engine ECC uses) —
        // same class of check as Standard/RequirementAssignment above.
        $nodesForeignParent = ComplianceNode::query()
            ->join('compliance_nodes as parents', 'compliance_nodes.parent_id', '=', 'parents.id')
            ->whereColumn('compliance_nodes.compliance_program_id', '!=', 'parents.compliance_program_id')
            ->count();
        $nodesForeignCycle = ComplianceNode::query()
            ->join('assessment_cycles', 'compliance_nodes.program_cycle_id', '=', 'assessment_cycles.id')
            ->whereColumn('compliance_nodes.compliance_program_id', '!=', 'assessment_cycles.compliance_program_id')
            ->count();
        // The standards<->node mirror was removed, so there is no longer a
        // link between the two that could cross programs (audit finding C2).
        // Evidence is checked against its node instead.
        $submissionsForeignNode = EvidenceSubmission::query()
            ->join('compliance_nodes', 'evidence_submissions.compliance_node_id', '=', 'compliance_nodes.id')
            ->whereColumn('evidence_submissions.compliance_program_id', '!=', 'compliance_nodes.compliance_program_id')
            ->count();

        $checks = [
            ['Standards linked to a cycle owned by a different program', $standardsForeignCycle],
            ['Assignments linked to a requirement owned by a different program', $assignmentsForeignRequirement],
            ['Assignments linked to a cycle owned by a different program', $assignmentsForeignCycle],
            ['Evidence submissions linked to an assignment owned by a different program', $submissionsForeignAssignment],
            ['Evidence submissions linked to a cycle owned by a different program', $submissionsForeignCycle],
            ['Program memberships referencing a nonexistent program', $membershipsOrphanedProgram],
            ['Hierarchy nodes linked to a parent owned by a different program', $nodesForeignParent],
            ['Hierarchy nodes linked to a cycle owned by a different program', $nodesForeignCycle],
            ['Evidence linked to a hierarchy node owned by a different program', $submissionsForeignNode],
        ];

        foreach ($checks as [$label, $count]) {
            $ok = $count === 0;
            $icon = $ok ? '<fg=green>OK</>' : '<fg=red>FAIL</>';
            $this->line(sprintf('  [%s] %-75s %d', $icon, $label, $count));
        }

        $this->newLine();

        // Pairwise shared-cycle check across EVERY pair of active programs
        // (not hardcoded to any two) — a standard belonging to program A
        // must never reference a cycle owned by program B.
        foreach ($programs as $a) {
            foreach ($programs as $b) {
                if ($a->id >= $b->id) {
                    continue;
                }
                $sharedCycles = AssessmentCycle::whereIn('id', function ($q) use ($a) {
                    $q->select('cycle_id')->from('standards')->where('compliance_program_id', $a->id);
                })->where('compliance_program_id', $b->id)->count();
                $label = "{$a->code}/{$b->code} shared-cycle contamination";
                $this->line(sprintf('  [%s] %-75s %d', $sharedCycles === 0 ? '<fg=green>OK</>' : '<fg=red>FAIL</>', $label, $sharedCycles));
                $checks[] = [$label, $sharedCycles];
            }
        }

        $failed = collect($checks)->contains(fn ($c) => $c[1] !== 0);

        $this->newLine();
        if ($failed) {
            $this->error('Cross-program contamination detected. Review the failing checks above.');

            return self::FAILURE;
        }

        $this->info('No cross-program contamination detected.');

        return self::SUCCESS;
    }
}
