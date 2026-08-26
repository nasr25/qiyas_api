<?php

namespace App\Services;

use App\Models\AssessmentCycle;
use App\Models\ComplianceNode;
use App\Models\ComplianceProgram;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * CycleService manages assessment cycle (ProgramCycle) lifecycle operations.
 * Enforces the business rule that only one cycle per ComplianceProgram can be
 * active at a time (scoped per-program, not globally, so Qiyas and a future
 * program such as Sumoud can each run their own active cycle independently).
 */
class CycleService
{
    /**
     * Creates a new assessment cycle.
     *
     * @param  array  $data  Cycle fields
     * @param  User  $creator  User creating the cycle
     * @param  ComplianceProgram|null  $program  Program the cycle belongs to; defaults to QIYAS
     *                                           for backward compatibility with the legacy /cycles route.
     */
    public function create(array $data, User $creator, ?ComplianceProgram $program = null): AssessmentCycle
    {
        $program ??= ComplianceProgram::where('code', 'QIYAS')->firstOrFail();

        $cycle = AssessmentCycle::create([
            'compliance_program_id' => $program->id,
            'name' => $data['name'],
            'name_ar' => $data['name_ar'] ?? $data['name'],
            'name_en' => $data['name_en'] ?? null,
            'year' => $data['year'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'status' => 'draft',
            'created_by' => $creator->id,
        ]);

        AuditService::log('cycle.created', "Assessment cycle '{$cycle->name}' created", $cycle, complianceProgramId: $program->id);

        return $cycle;
    }

    /**
     * Activates a draft cycle.
     * Throws an exception if another cycle in the same program is already active.
     */
    public function activate(AssessmentCycle $cycle): AssessmentCycle
    {
        if ($cycle->status !== 'draft') {
            throw new \RuntimeException('Only draft cycles can be activated.');
        }

        $activeExists = AssessmentCycle::where('status', 'active')
            ->where('compliance_program_id', $cycle->compliance_program_id)
            ->exists();
        if ($activeExists) {
            throw new \RuntimeException('Another cycle is currently active for this program. Close it before activating a new one.');
        }

        $cycle->update([
            'status' => 'active',
            'is_current' => true,
            'activated_at' => now(),
        ]);

        AuditService::log('cycle.activated', "Cycle '{$cycle->name}' activated", $cycle, complianceProgramId: $cycle->compliance_program_id);

        return $cycle->fresh();
    }

    /**
     * Closes an active cycle, preventing further uploads or edits.
     */
    public function close(AssessmentCycle $cycle, ?float $finalScore = null, ?string $closingNotes = null, ?User $closer = null): AssessmentCycle
    {
        if ($cycle->status !== 'active') {
            throw new \RuntimeException('Only active cycles can be closed.');
        }

        $cycle->update([
            'status' => 'closed',
            'is_current' => false,
            'closed_at' => now(),
            'closed_by' => $closer?->id,
            'final_score' => $finalScore,
            'closing_notes' => $closingNotes,
        ]);

        AuditService::log('cycle.closed', "Cycle '{$cycle->name}' closed", $cycle, complianceProgramId: $cycle->compliance_program_id);

        return $cycle->fresh();
    }

    /**
     * Archives a closed cycle.
     */
    public function archive(AssessmentCycle $cycle): AssessmentCycle
    {
        if ($cycle->status !== 'closed') {
            throw new \RuntimeException('Only closed cycles can be archived.');
        }

        $cycle->update(['status' => 'archived']);
        AuditService::log('cycle.archived', "Cycle '{$cycle->name}' archived", $cycle, complianceProgramId: $cycle->compliance_program_id);

        return $cycle->fresh();
    }

    /**
     * Deep-copies a cycle's hierarchy content into a new cycle.
     *
     * Replaces the legacy `copyStandards()`, which cloned `standards` rows.
     * This preserves what actually matters in the dynamic engine: each
     * node's level binding and its position in the parent chain, at whatever
     * depth the program configured. Assignments and evidence are NOT copied
     * — a new cycle starts with fresh work against copied content.
     *
     * Nodes are created breadth-first so a parent always exists before its
     * children, and the source→target id map re-links the chain.
     *
     * @return int number of nodes copied
     */
    public function copyHierarchy(AssessmentCycle $source, AssessmentCycle $target): int
    {
        return DB::transaction(function () use ($source, $target) {
            $sourceNodes = ComplianceNode::where('program_cycle_id', $source->id)
                ->orderBy('level')->orderBy('sort_order')->orderBy('id')
                ->get();

            $structureVersionId = app(HierarchyDefinitionService::class)
                ->currentStructureVersion($target->program)?->id;

            $map = [];
            $copied = 0;

            foreach ($sourceNodes as $node) {
                // A node whose parent was not copied (shouldn't happen given
                // the ordering) is skipped rather than silently re-rooted.
                if ($node->parent_id !== null && ! isset($map[$node->parent_id])) {
                    continue;
                }

                $clone = ComplianceNode::create([
                    ...$node->only([
                        'compliance_program_id', 'hierarchy_level_id', 'node_type', 'level',
                        'code', 'name_ar', 'name_en', 'description_ar', 'description_en',
                        'objective_ar', 'objective_en', 'guidance_ar', 'guidance_en',
                        'weight', 'default_due_date', 'sort_order', 'is_assessable',
                        'is_assignable_override', 'is_assessable_override', 'accepts_evidence_override',
                        'metadata',
                    ]),
                    'program_cycle_id' => $target->id,
                    'structure_version_id' => $structureVersionId,
                    'parent_id' => $node->parent_id ? $map[$node->parent_id] : null,
                    'status' => 'active',
                    'created_by' => $target->created_by,
                    'updated_by' => $target->created_by,
                ]);

                $map[$node->id] = $clone->id;
                $copied++;
            }

            AuditService::log(
                'cycle.hierarchy_copied',
                "Copied {$copied} node(s) from cycle '{$source->name}' into '{$target->name}'",
                $target,
                complianceProgramId: $target->compliance_program_id,
            );

            return $copied;
        });
    }
}
