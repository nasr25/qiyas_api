<?php

namespace Database\Seeders;

use App\Models\ComplianceNode;
use App\Models\ComplianceProgram;
use App\Models\Department;
use App\Models\EvidenceFile;
use App\Models\ProgramUserRole;
use App\Models\User;
use App\Services\HierarchyDefinitionService;
use App\Services\WorkflowService;
use Database\Seeders\Concerns\NonProductionSeeder;
use Illuminate\Database\Seeder;
use Throwable;

/**
 * Drives real assignments and evidence through the workflow engine against
 * node-based requirements, so every program has live dashboard/report data.
 *
 * Like HierarchyContentSeeder this is program-agnostic: it asks each
 * program's structure which levels are assignable and assigns nodes at
 * whatever depth that turns out to be — level 3 for Sumoud, level 5 for
 * NDMO. Assignments land on genuinely different depths per program, which
 * is exactly what the old two-level mirror could not represent.
 */
class HierarchyWorkflowSeeder extends Seeder
{
    use NonProductionSeeder;

    /**
     * Assignments created per program. Six rather than four so each of the
     * two departments ends up with a submitted item awaiting review —
     * cross-department authorization tests need a Department B submission
     * to attempt, and with four the distribution left that to chance.
     */
    private const PER_PROGRAM = 6;

    public function run(): void
    {
        $this->guardAgainstProduction();

        $workflow = app(WorkflowService::class);
        $structures = app(HierarchyDefinitionService::class);
        $departments = Department::active()->orderBy('id')->take(2)->get();

        if ($departments->isEmpty()) {
            $this->command?->warn('No active departments — skipped.');

            return;
        }

        foreach (ComplianceProgram::orderBy('code')->get() as $program) {
            // Assign at a level that is BOTH assignable and evidence-bearing:
            // this seeder submits evidence, and a level may legitimately be
            // assignable without accepting evidence (a Criterion groups
            // Application Requirements, which carry the files).
            $levels = collect($structures->levels($program));
            $assignableLevelIds = $levels
                ->where('is_assignable', true)->where('accepts_evidence', true)->pluck('id');

            if ($assignableLevelIds->isEmpty()) {
                $assignableLevelIds = $levels->where('is_assignable', true)->pluck('id');
            }

            if ($assignableLevelIds->isEmpty()) {
                continue;
            }

            $nodes = ComplianceNode::where('compliance_program_id', $program->id)
                ->whereIn('hierarchy_level_id', $assignableLevelIds)
                ->orderBy('id')->take(self::PER_PROGRAM)->get();

            $manager = $this->programManager($program);
            if (! $manager) {
                continue;
            }

            $made = 0;
            $submitted = 0;
            foreach ($nodes as $i => $node) {
                $department = $departments[$i % $departments->count()];
                $employee = $this->employeeIn($program, $department);

                try {
                    $assignment = $workflow->assign(
                        $node, $program, $manager, $department, $employee,
                        now()->addMonths(2)->toDateString(), 'normal', null, null,
                    );
                    $made++;

                    // The first assignment for each department stays a draft
                    // so employee screens have something actionable; the rest
                    // are submitted, which puts an item in BOTH departments'
                    // review queues — cross-department authorization tests
                    // need a Department B submission to attempt.
                    if ($i < $departments->count() || ! $employee) {
                        continue;
                    }

                    $submission = $workflow->getOrCreateDraft($assignment, $employee);
                    $this->attachEvidence($submission, $employee);
                    $workflow->submit($submission->fresh(), $employee, null);
                    $submitted++;
                } catch (Throwable $e) {
                    // Surfaced rather than swallowed: a refusal here means the
                    // structure says this node is not assignable, which is a
                    // finding, not noise.
                    $this->command?->warn("  {$program->code} {$node->code}: {$e->getMessage()}");
                }
            }

            $this->command?->info("  {$program->code}: {$made} assignment(s), {$submitted} submitted, at level "
                .($nodes->first()?->hierarchyLevel?->key ?? '—').'.');
        }
    }

    /** Submitting requires at least one evidence file. */
    private function attachEvidence($submission, User $employee): void
    {
        EvidenceFile::firstOrCreate(
            ['evidence_submission_id' => $submission->id, 'original_name' => 'evidence.pdf'],
            [
                'stored_name' => "seed-{$submission->id}.pdf",
                'storage_path' => "evidence/seed/seed-{$submission->id}.pdf",
                'mime_type' => 'application/pdf',
                'file_size' => 10240,
                'file_hash' => hash('sha256', "seed-evidence-{$submission->id}"),
                'uploaded_by' => $employee->id,
                'uploaded_at' => now(),
                'is_active' => true,
            ],
        );
    }

    private function programManager(ComplianceProgram $program): ?User
    {
        $roleUserId = ProgramUserRole::where('compliance_program_id', $program->id)
            ->where('role_key', 'program-manager')->where('is_active', true)->value('user_id');

        return $roleUserId ? User::find($roleUserId) : User::where('username', 'superadmin')->first();
    }

    private function employeeIn(ComplianceProgram $program, Department $department): ?User
    {
        $userId = ProgramUserRole::where('compliance_program_id', $program->id)
            ->where('role_key', 'employee')
            ->where('department_id', $department->id)
            ->where('is_active', true)
            ->value('user_id');

        return $userId ? User::find($userId) : null;
    }
}
