<?php

namespace Tests\Feature\Workflow;

use App\Models\WorkflowDecision;
use App\Services\WorkflowService;
use Illuminate\Support\Facades\Artisan;

/**
 * `compliance:verify-qiyas` must pass cleanly on a healthy dataset and must
 * detect a deliberately introduced inconsistency — proving the checks are
 * real assertions, not just counts that always read zero.
 */
class DataIntegrityCommandTest extends WorkflowTestCase
{
    public function test_command_passes_on_a_healthy_dataset(): void
    {
        $assignment = app(WorkflowService::class)->assign(
            $this->requirement, $this->qiyas, $this->programManager, $this->deptA, null, '2026-12-01', null, null, null,
        );

        $exitCode = Artisan::call('compliance:verify-qiyas');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('All Qiyas data-integrity checks passed.', Artisan::output());
    }

    public function test_command_detects_an_approved_submission_missing_its_final_decision(): void
    {
        $assignment = app(WorkflowService::class)->assign(
            $this->requirement, $this->qiyas, $this->programManager, $this->deptA, null, '2026-12-01', null, null, null,
        );
        $submission = $assignment->submissions()->create([
            'compliance_program_id' => $this->qiyas->id,
            'program_cycle_id' => $this->cycle->id,
            'requirement_id' => $this->requirement->id,
            'department_id' => $this->deptA->id,
            'submitted_by' => $this->deptAEmployee->id,
            'version_number' => 1,
            // A status the real WorkflowService would never produce without
            // a matching WorkflowDecision — simulates data corruption/a bug
            // that bypassed the domain service, which is exactly what this
            // command exists to catch.
            'status' => 'approved',
            'current_stage' => 'completed',
        ]);

        $exitCode = Artisan::call('compliance:verify-qiyas');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Approved submissions without a final Program Manager decision', Artisan::output());

        // Confirm the check is read-only: it must not have "fixed" anything.
        $this->assertSame(0, WorkflowDecision::count());
    }
}
