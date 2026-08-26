<?php

namespace Tests\Feature\Workflow;

use App\Services\WorkflowService;
use Illuminate\Http\UploadedFile;

class ExecutiveViewerTest extends WorkflowTestCase
{
    public function test_executive_viewer_can_view_program_dashboard(): void
    {
        $executive = $this->makeUser('executive');

        $this->getJson('/api/v1/programs/QIYAS/dashboard', $this->authHeader($executive))->assertOk();
    }

    public function test_executive_viewer_cannot_assign_requirements(): void
    {
        $executive = $this->makeUser('executive');

        $this->postJson('/api/v1/programs/QIYAS/assignments', [
            'requirement_id' => $this->requirement->id,
            'department_id' => $this->deptA->id,
        ], $this->authHeader($executive))->assertStatus(403);
    }

    public function test_executive_viewer_cannot_approve_a_submission(): void
    {
        $executive = $this->makeUser('executive');
        $workflow = app(WorkflowService::class);
        $assignment = $workflow->assign($this->requirement, $this->qiyas, $this->programManager, $this->deptA, null, '2026-12-01', null, null, null);
        $draft = $workflow->getOrCreateDraft($assignment, $this->deptAEmployee);
        $workflow->addFile($draft, UploadedFile::fake()->create('e.pdf', 10, 'application/pdf'), $this->deptAEmployee);
        $submission = $workflow->submit($draft, $this->deptAEmployee, null);

        $this->postJson("/api/v1/programs/QIYAS/reviews/department-manager/{$submission->id}/approve", [], $this->authHeader($executive))
            ->assertStatus(403);
    }
}
