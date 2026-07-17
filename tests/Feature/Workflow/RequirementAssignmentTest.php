<?php

namespace Tests\Feature\Workflow;

use App\Models\RequirementAssignment;
use App\Services\WorkflowService;

class RequirementAssignmentTest extends WorkflowTestCase
{
    public function test_program_manager_can_assign_a_requirement(): void
    {
        $this->postJson('/api/v1/programs/QIYAS/assignments', [
            'requirement_id' => $this->requirement->id,
            'department_id' => $this->deptA->id,
            'due_date' => '2026-12-01',
        ], $this->authHeader($this->programManager))
            ->assertCreated()
            ->assertJsonPath('data.department.id', $this->deptA->id);

        $this->assertDatabaseHas('requirement_assignments', [
            'requirement_id' => $this->requirement->id, 'department_id' => $this->deptA->id, 'status' => 'active',
        ]);
    }

    public function test_unauthorized_user_cannot_assign(): void
    {
        $this->postJson('/api/v1/programs/QIYAS/assignments', [
            'requirement_id' => $this->requirement->id,
            'department_id' => $this->deptA->id,
        ], $this->authHeader($this->deptAEmployee))
            ->assertStatus(403);
    }

    public function test_program_manager_cannot_assign_in_another_program(): void
    {
        $this->grantProgramRole($this->programManager, $this->otherProgram, 'program-manager');

        $this->postJson('/api/v1/programs/OTHER/assignments', [
            'requirement_id' => $this->requirement->id, // belongs to QIYAS, not OTHER
            'department_id' => $this->deptA->id,
        ], $this->authHeader($this->programManager))
            ->assertStatus(404);
    }

    public function test_assignment_visible_only_to_assigned_department(): void
    {
        $assignment = $this->assign($this->deptA);

        $this->getJson("/api/v1/programs/QIYAS/assignments/{$assignment->id}", $this->authHeader($this->deptAEmployee))->assertOk();
        $this->getJson("/api/v1/programs/QIYAS/assignments/{$assignment->id}", $this->authHeader($this->deptBEmployee))->assertStatus(403);
    }

    public function test_reassignment_preserves_history_and_revokes_old_department(): void
    {
        $assignment = $this->assign($this->deptA);

        $this->postJson("/api/v1/programs/QIYAS/assignments/{$assignment->id}/reassign", [
            'department_id' => $this->deptB->id,
            'reason' => 'Department A no longer owns this process.',
        ], $this->authHeader($this->programManager))->assertOk();

        $this->assertDatabaseHas('requirement_assignments', ['id' => $assignment->id, 'status' => 'reassigned']);
        $newAssignment = RequirementAssignment::where('previous_assignment_id', $assignment->id)->first();
        $this->assertNotNull($newAssignment);
        $this->assertEquals($this->deptB->id, $newAssignment->department_id);

        // Old department (A) no longer has access to the (now historical) requirement via the new active assignment.
        $this->getJson("/api/v1/programs/QIYAS/assignments/{$newAssignment->id}", $this->authHeader($this->deptAEmployee))->assertStatus(403);
        $this->getJson("/api/v1/programs/QIYAS/assignments/{$newAssignment->id}", $this->authHeader($this->deptBEmployee))->assertOk();
    }

    public function test_reassignment_requires_a_reason(): void
    {
        $assignment = $this->assign($this->deptA);

        $this->postJson("/api/v1/programs/QIYAS/assignments/{$assignment->id}/reassign", [
            'department_id' => $this->deptB->id,
        ], $this->authHeader($this->programManager))->assertStatus(422);
    }

    public function test_assignment_list_returns_a_flat_data_array(): void
    {
        $this->assign($this->deptA);

        $response = $this->getJson('/api/v1/programs/QIYAS/assignments', $this->authHeader($this->programManager))->assertOk();

        // A paginator serialized directly into `data` nests as
        // {current_page, data: [...], ...} instead of a flat array —
        // asserting the shape here catches that regression explicitly.
        $this->assertIsArray($response->json('data'));
        $this->assertArrayHasKey('requirement', $response->json('data.0'));
        $this->assertArrayHasKey('code', $response->json('data.0.requirement'));
    }

    public function test_cannot_double_assign_the_same_requirement(): void
    {
        $this->assign($this->deptA);

        $this->postJson('/api/v1/programs/QIYAS/assignments', [
            'requirement_id' => $this->requirement->id,
            'department_id' => $this->deptB->id,
        ], $this->authHeader($this->programManager))->assertStatus(409);
    }

    private function assign($department): RequirementAssignment
    {
        return app(WorkflowService::class)->assign(
            $this->requirement, $this->qiyas, $this->programManager, $department, null, '2026-12-01', null, null, null,
        );
    }
}
