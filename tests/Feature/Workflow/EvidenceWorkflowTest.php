<?php

namespace Tests\Feature\Workflow;

use App\Models\EvidenceSubmission;
use App\Models\RequirementAssignment;
use App\Services\WorkflowService;
use Illuminate\Http\UploadedFile;

/**
 * Covers the full happy path plus the required rejection/resubmission
 * invariants: every rejection returns to the Employee, and resubmission
 * always restarts at the Department Manager regardless of which stage
 * rejected the previous version.
 */
class EvidenceWorkflowTest extends WorkflowTestCase
{
    public function test_employee_can_create_draft_upload_and_submit(): void
    {
        $assignment = $this->assign($this->deptA);

        $draft = $this->postJson("/api/v1/programs/QIYAS/assignments/{$assignment->id}/draft", [], $this->authHeader($this->deptAEmployee))
            ->assertOk()->json('data');

        $this->postJson("/api/v1/programs/QIYAS/evidence-submissions/{$draft['id']}/files", [
            'file' => UploadedFile::fake()->create('evidence.pdf', 100, 'application/pdf'),
        ], $this->authHeader($this->deptAEmployee))->assertCreated();

        $this->postJson("/api/v1/programs/QIYAS/evidence-submissions/{$draft['id']}/submit", [], $this->authHeader($this->deptAEmployee))
            ->assertOk()->assertJsonPath('data.status', 'pending_department_manager');
    }

    public function test_unsafe_file_extension_is_rejected(): void
    {
        $assignment = $this->assign($this->deptA);
        $draft = $this->openDraft($assignment, $this->deptAEmployee);

        $this->postJson("/api/v1/programs/QIYAS/evidence-submissions/{$draft->id}/files", [
            'file' => UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload'),
        ], $this->authHeader($this->deptAEmployee))->assertStatus(422);
    }

    public function test_employee_cannot_edit_while_pending_review(): void
    {
        $assignment = $this->assign($this->deptA);
        $submission = $this->submitWithFile($assignment, $this->deptAEmployee);

        $this->postJson("/api/v1/programs/QIYAS/evidence-submissions/{$submission->id}/submit", [], $this->authHeader($this->deptAEmployee))
            ->assertStatus(403);
    }

    public function test_employee_cannot_access_another_departments_submission(): void
    {
        $assignment = $this->assign($this->deptA);
        $submission = $this->submitWithFile($assignment, $this->deptAEmployee);

        $this->getJson("/api/v1/programs/QIYAS/evidence-submissions/{$submission->id}", $this->authHeader($this->deptBEmployee))
            ->assertStatus(403);
    }

    public function test_department_manager_can_approve_own_department(): void
    {
        $assignment = $this->assign($this->deptA);
        $submission = $this->submitWithFile($assignment, $this->deptAEmployee);

        $this->postJson("/api/v1/programs/QIYAS/reviews/department-manager/{$submission->id}/approve", [], $this->authHeader($this->deptAManager))
            ->assertOk()->assertJsonPath('data.status', 'pending_auditor');
    }

    public function test_department_manager_cannot_review_another_department(): void
    {
        $assignment = $this->assign($this->deptA);
        $submission = $this->submitWithFile($assignment, $this->deptAEmployee);

        $this->postJson("/api/v1/programs/QIYAS/reviews/department-manager/{$submission->id}/approve", [], $this->authHeader($this->deptBManager))
            ->assertStatus(403);
    }

    public function test_department_manager_rejection_reason_is_mandatory(): void
    {
        $assignment = $this->assign($this->deptA);
        $submission = $this->submitWithFile($assignment, $this->deptAEmployee);

        $this->postJson("/api/v1/programs/QIYAS/reviews/department-manager/{$submission->id}/reject", [], $this->authHeader($this->deptAManager))
            ->assertStatus(422);
    }

    public function test_department_manager_rejection_returns_directly_to_employee(): void
    {
        $assignment = $this->assign($this->deptA);
        $submission = $this->submitWithFile($assignment, $this->deptAEmployee);

        $this->postJson("/api/v1/programs/QIYAS/reviews/department-manager/{$submission->id}/reject", [
            'reason' => 'Missing signature.',
        ], $this->authHeader($this->deptAManager))
            ->assertOk()
            ->assertJsonPath('data.status', 'returned_for_revision');

        $this->assertEquals('employee', $submission->fresh()->current_stage);
    }

    public function test_auditor_cannot_review_before_department_manager_approval(): void
    {
        $assignment = $this->assign($this->deptA);
        $submission = $this->submitWithFile($assignment, $this->deptAEmployee);

        $this->postJson("/api/v1/programs/QIYAS/reviews/auditor/{$submission->id}/approve", [], $this->authHeader($this->auditor))
            ->assertStatus(409);
    }

    public function test_auditor_can_approve_after_department_manager_approval(): void
    {
        $submission = $this->approvedByDepartmentManager();

        $this->postJson("/api/v1/programs/QIYAS/reviews/auditor/{$submission->id}/approve", [], $this->authHeader($this->auditor))
            ->assertOk()->assertJsonPath('data.status', 'pending_program_manager');
    }

    public function test_auditor_rejection_returns_directly_to_employee_not_department_manager(): void
    {
        $submission = $this->approvedByDepartmentManager();

        $this->postJson("/api/v1/programs/QIYAS/reviews/auditor/{$submission->id}/reject", [
            'reason' => 'Evidence does not match the requirement.',
        ], $this->authHeader($this->auditor))
            ->assertOk()->assertJsonPath('data.status', 'returned_for_revision');

        $this->assertEquals('employee', $submission->fresh()->current_stage);
    }

    public function test_program_manager_cannot_approve_before_auditor_approval(): void
    {
        $submission = $this->approvedByDepartmentManager();

        $this->postJson("/api/v1/programs/QIYAS/reviews/program-manager/{$submission->id}/approve", [], $this->authHeader($this->programManager))
            ->assertStatus(409);
    }

    public function test_program_manager_final_approval_completes_the_requirement(): void
    {
        $submission = $this->approvedByAuditor();

        $this->postJson("/api/v1/programs/QIYAS/reviews/program-manager/{$submission->id}/approve", [], $this->authHeader($this->programManager))
            ->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('requirement_assignments', ['id' => $submission->requirement_assignment_id, 'status' => 'completed']);
    }

    public function test_program_manager_rejection_returns_directly_to_employee(): void
    {
        $submission = $this->approvedByAuditor();

        $this->postJson("/api/v1/programs/QIYAS/reviews/program-manager/{$submission->id}/reject", [
            'reason' => 'Final quality check failed.',
        ], $this->authHeader($this->programManager))
            ->assertOk()->assertJsonPath('data.status', 'returned_for_revision');

        $this->assertEquals('employee', $submission->fresh()->current_stage);
    }

    public function test_resubmission_after_auditor_rejection_restarts_at_department_manager_not_auditor(): void
    {
        $submission = $this->approvedByDepartmentManager();
        $this->postJson("/api/v1/programs/QIYAS/reviews/auditor/{$submission->id}/reject", ['reason' => 'Incomplete.'], $this->authHeader($this->auditor))->assertOk();

        $assignment = RequirementAssignment::find($submission->requirement_assignment_id);
        $newDraft = $this->openDraft($assignment, $this->deptAEmployee);
        $this->assertEquals(2, $newDraft->version_number);
        $this->uploadFile($newDraft, $this->deptAEmployee);

        $resubmitted = $this->postJson("/api/v1/programs/QIYAS/evidence-submissions/{$newDraft->id}/submit", [], $this->authHeader($this->deptAEmployee))
            ->assertOk()->json('data');

        // Must land on the Department Manager, never skip back to the Auditor
        // who rejected it.
        $this->assertEquals('pending_department_manager', $resubmitted['status']);
    }

    public function test_review_queue_list_returns_a_flat_data_array(): void
    {
        $this->submitWithFile($this->assign($this->deptA), $this->deptAEmployee);

        $response = $this->getJson('/api/v1/programs/QIYAS/reviews/department-manager', $this->authHeader($this->deptAManager))->assertOk();

        $this->assertIsArray($response->json('data'));
        $this->assertArrayHasKey('requirement', $response->json('data.0'));
    }

    public function test_conflicting_double_decision_returns_409(): void
    {
        $assignment = $this->assign($this->deptA);
        $submission = $this->submitWithFile($assignment, $this->deptAEmployee);

        $this->postJson("/api/v1/programs/QIYAS/reviews/department-manager/{$submission->id}/approve", [], $this->authHeader($this->deptAManager))->assertOk();

        // Same submission, decided again — must not silently succeed twice.
        $this->postJson("/api/v1/programs/QIYAS/reviews/department-manager/{$submission->id}/approve", [], $this->authHeader($this->deptAManager))
            ->assertStatus(409);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function assign($department): RequirementAssignment
    {
        return app(WorkflowService::class)->assign(
            $this->requirement, $this->qiyas, $this->programManager, $department, null, '2026-12-01', null, null, null,
        );
    }

    private function openDraft(RequirementAssignment $assignment, $employee): EvidenceSubmission
    {
        return app(WorkflowService::class)->getOrCreateDraft($assignment, $employee);
    }

    private function uploadFile(EvidenceSubmission $submission, $employee): void
    {
        app(WorkflowService::class)->addFile($submission, UploadedFile::fake()->create('evidence.pdf', 100, 'application/pdf'), $employee);
    }

    private function submitWithFile(RequirementAssignment $assignment, $employee): EvidenceSubmission
    {
        $draft = $this->openDraft($assignment, $employee);
        $this->uploadFile($draft, $employee);

        return app(WorkflowService::class)->submit($draft, $employee, null);
    }

    private function approvedByDepartmentManager(): EvidenceSubmission
    {
        $assignment = $this->assign($this->deptA);
        $submission = $this->submitWithFile($assignment, $this->deptAEmployee);

        return app(WorkflowService::class)->approve($submission, $this->deptAManager, 'department_manager', 'department-manager', null);
    }

    private function approvedByAuditor(): EvidenceSubmission
    {
        $submission = $this->approvedByDepartmentManager();

        return app(WorkflowService::class)->approve($submission, $this->auditor, 'auditor', 'auditor', null);
    }
}
