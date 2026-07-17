<?php

namespace Tests\Feature\Workflow;

use App\Models\RequirementAssignment;
use App\Services\ExtensionService;
use App\Services\WorkflowService;

class ExtensionRequestTest extends WorkflowTestCase
{
    public function test_employee_can_request_an_extension(): void
    {
        $assignment = $this->assign($this->deptA);

        $this->postJson("/api/v1/programs/QIYAS/assignments/{$assignment->id}/extension-requests", [
            'requested_due_date' => now()->addDays(30)->toDateString(),
            'reason' => 'Need more time to collect stakeholder sign-off.',
        ], $this->authHeader($this->deptAEmployee))
            ->assertCreated()->assertJsonPath('data.status', 'pending');
    }

    public function test_department_manager_cannot_decide_an_extension(): void
    {
        $assignment = $this->assign($this->deptA);
        $extension = app(ExtensionService::class)->request($assignment, $this->deptAEmployee, now()->addDays(30)->toDateString(), 'Reason.');

        $this->postJson("/api/v1/programs/QIYAS/reviews/auditor/extension-requests/{$extension->id}/approve", [], $this->authHeader($this->deptAManager))
            ->assertStatus(403);
    }

    public function test_auditor_can_approve_an_extension(): void
    {
        $assignment = $this->assign($this->deptA);
        $extension = app(ExtensionService::class)->request($assignment, $this->deptAEmployee, now()->addDays(30)->toDateString(), 'Reason.');

        $this->postJson("/api/v1/programs/QIYAS/reviews/auditor/extension-requests/{$extension->id}/approve", [], $this->authHeader($this->auditor))
            ->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_auditor_rejection_requires_a_reason(): void
    {
        $assignment = $this->assign($this->deptA);
        $extension = app(ExtensionService::class)->request($assignment, $this->deptAEmployee, now()->addDays(30)->toDateString(), 'Reason.');

        $this->postJson("/api/v1/programs/QIYAS/reviews/auditor/extension-requests/{$extension->id}/reject", [], $this->authHeader($this->auditor))
            ->assertStatus(422);
    }

    public function test_approved_extension_preserves_original_due_date(): void
    {
        $assignment = $this->assign($this->deptA, '2026-12-01');
        $originalDueDate = $assignment->original_due_date->toDateString();

        $extension = app(ExtensionService::class)->request($assignment, $this->deptAEmployee, '2026-12-15', 'Reason.');
        app(ExtensionService::class)->decide($extension, $this->auditor, 'approved', null, null);

        $fresh = $assignment->fresh();
        $this->assertEquals($originalDueDate, $fresh->original_due_date->toDateString());
        $this->assertEquals('2026-12-15', $fresh->effective_due_date->toDateString());
    }

    public function test_only_one_pending_extension_request_allowed(): void
    {
        $assignment = $this->assign($this->deptA);
        app(ExtensionService::class)->request($assignment, $this->deptAEmployee, now()->addDays(20)->toDateString(), 'First.');

        $this->postJson("/api/v1/programs/QIYAS/assignments/{$assignment->id}/extension-requests", [
            'requested_due_date' => now()->addDays(30)->toDateString(),
            'reason' => 'Second.',
        ], $this->authHeader($this->deptAEmployee))
            ->assertStatus(409);
    }

    public function test_auditor_cannot_decide_extension_in_unauthorized_program(): void
    {
        $assignment = $this->assign($this->deptA);
        $extension = app(ExtensionService::class)->request($assignment, $this->deptAEmployee, now()->addDays(30)->toDateString(), 'Reason.');

        // An auditor with no role in OTHER attempting to reach it via a mismatched program context.
        $this->postJson("/api/v1/programs/OTHER/reviews/auditor/extension-requests/{$extension->id}/approve", [], $this->authHeader($this->auditor))
            ->assertStatus(404);
    }

    private function assign($department, ?string $dueDate = null): RequirementAssignment
    {
        return app(WorkflowService::class)->assign(
            $this->requirement, $this->qiyas, $this->programManager, $department, null,
            $dueDate ?? now()->addDays(5)->toDateString(), null, null, null,
        );
    }
}
