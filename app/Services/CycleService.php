<?php

namespace App\Services;

use App\Models\AssessmentCycle;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * CycleService manages assessment cycle lifecycle operations.
 * Enforces the business rule that only one cycle can be active at a time.
 */
class CycleService
{
    /**
     * Creates a new assessment cycle.
     *
     * @param array $data    Cycle fields
     * @param User  $creator User creating the cycle
     * @return AssessmentCycle
     */
    public function create(array $data, User $creator): AssessmentCycle
    {
        $cycle = AssessmentCycle::create([
            'name'       => $data['name'],
            'year'       => $data['year'],
            'start_date' => $data['start_date'],
            'end_date'   => $data['end_date'],
            'status'     => 'draft',
            'created_by' => $creator->id,
        ]);

        AuditService::log('cycle.created', "Assessment cycle '{$cycle->name}' created", $cycle);

        return $cycle;
    }

    /**
     * Activates a draft cycle.
     * Throws an exception if another cycle is already active.
     *
     * @param AssessmentCycle $cycle
     * @return AssessmentCycle
     */
    public function activate(AssessmentCycle $cycle): AssessmentCycle
    {
        if ($cycle->status !== 'draft') {
            throw new \RuntimeException('Only draft cycles can be activated.');
        }

        $activeExists = AssessmentCycle::where('status', 'active')->exists();
        if ($activeExists) {
            throw new \RuntimeException('Another cycle is currently active. Close it before activating a new one.');
        }

        $cycle->update([
            'status'       => 'active',
            'activated_at' => now(),
        ]);

        AuditService::log('cycle.activated', "Cycle '{$cycle->name}' activated", $cycle);

        return $cycle->fresh();
    }

    /**
     * Closes an active cycle, preventing further uploads or edits.
     *
     * @param AssessmentCycle $cycle
     * @param float|null      $finalScore
     * @param string|null     $closingNotes
     * @return AssessmentCycle
     */
    public function close(AssessmentCycle $cycle, ?float $finalScore = null, ?string $closingNotes = null): AssessmentCycle
    {
        if ($cycle->status !== 'active') {
            throw new \RuntimeException('Only active cycles can be closed.');
        }

        $cycle->update([
            'status'        => 'closed',
            'closed_at'     => now(),
            'final_score'   => $finalScore,
            'closing_notes' => $closingNotes,
        ]);

        AuditService::log('cycle.closed', "Cycle '{$cycle->name}' closed", $cycle);

        return $cycle->fresh();
    }

    /**
     * Archives a closed cycle.
     *
     * @param AssessmentCycle $cycle
     * @return AssessmentCycle
     */
    public function archive(AssessmentCycle $cycle): AssessmentCycle
    {
        if ($cycle->status !== 'closed') {
            throw new \RuntimeException('Only closed cycles can be archived.');
        }

        $cycle->update(['status' => 'archived']);
        AuditService::log('cycle.archived', "Cycle '{$cycle->name}' archived", $cycle);

        return $cycle->fresh();
    }

    /**
     * Copies standards (and their evidence requirements) from one cycle to a new one.
     * Used when creating a new cycle based on the previous year's standards.
     *
     * @param AssessmentCycle $source  Source cycle
     * @param AssessmentCycle $target  New cycle
     * @return int  Number of standards copied
     */
    public function copyStandards(AssessmentCycle $source, AssessmentCycle $target): int
    {
        return DB::transaction(function () use ($source, $target) {
            $count = 0;

            foreach ($source->standards()->with('evidenceRequirements', 'departments')->get() as $standard) {
                $newStandard = Standard::create([
                    'cycle_id'        => $target->id,
                    'standard_number' => $standard->standard_number,
                    'name_ar'         => $standard->name_ar,
                    'name_en'         => $standard->name_en,
                    'description'     => $standard->description,
                    'version'         => $standard->version,
                    'weight'          => $standard->weight,
                    'due_date'        => null, // Reset due date for new cycle
                    'is_active'       => true,
                ]);

                // Copy evidence requirements
                foreach ($standard->evidenceRequirements as $req) {
                    $newStandard->evidenceRequirements()->create([
                        'title_ar'     => $req->title_ar,
                        'title_en'     => $req->title_en,
                        'description'  => $req->description,
                        'is_mandatory' => $req->is_mandatory,
                        'sort_order'   => $req->sort_order,
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
