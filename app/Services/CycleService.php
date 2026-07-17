<?php

namespace App\Services;

use App\Models\AssessmentCycle;
use App\Models\ComplianceProgram;
use App\Models\Standard;
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
     * Copies standards (and their evidence requirements) from one cycle to a new one.
     * Used when creating a new cycle based on the previous year's standards.
     *
     * @param  AssessmentCycle  $source  Source cycle
     * @param  AssessmentCycle  $target  New cycle
     * @return int Number of standards copied
     */
    public function copyStandards(AssessmentCycle $source, AssessmentCycle $target): int
    {
        return DB::transaction(function () use ($source, $target) {
            $count = 0;

            foreach ($source->standards()->with('evidenceRequirements', 'departments')->get() as $standard) {
                $newStandard = Standard::create([
                    'cycle_id' => $target->id,
                    'standard_number' => $standard->standard_number,
                    'name_ar' => $standard->name_ar,
                    'name_en' => $standard->name_en,
                    'description' => $standard->description,
                    'version' => $standard->version,
                    'weight' => $standard->weight,
                    'due_date' => null, // Reset due date for new cycle
                    'is_active' => true,
                ]);

                // Copy evidence requirements
                foreach ($standard->evidenceRequirements as $req) {
                    $newStandard->evidenceRequirements()->create([
                        'title_ar' => $req->title_ar,
                        'title_en' => $req->title_en,
                        'description' => $req->description,
                        'is_mandatory' => $req->is_mandatory,
                        'sort_order' => $req->sort_order,
                    ]);
                }

                // Copy department assignments
                foreach ($standard->departments as $dept) {
                    $newStandard->departments()->attach($dept->id, [
                        'assigned_at' => now(),
                        'assigned_by' => auth()->id(),
                    ]);
                }

                $count++;
            }

            AuditService::log(
                'cycle.standards_copied',
                "Copied {$count} standards from cycle #{$source->id} to cycle #{$target->id}",
                $target
            );

            return $count;
        });
    }
}
