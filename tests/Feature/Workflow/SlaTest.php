<?php

namespace Tests\Feature\Workflow;

use App\Models\NotificationLog;
use App\Models\SlaInstance;
use App\Services\WorkflowService;
use Illuminate\Http\UploadedFile;

class SlaTest extends WorkflowTestCase
{
    public function test_sla_instance_opens_for_employee_stage_on_assignment(): void
    {
        $assignment = app(WorkflowService::class)->assign(
            $this->requirement, $this->qiyas, $this->programManager, $this->deptA, null, '2026-12-01', null, null, null,
        );

        $this->assertDatabaseHas('sla_instances', [
            'requirement_assignment_id' => $assignment->id, 'stage' => 'employee', 'status' => 'active',
        ]);
    }

    public function test_sla_completes_when_stage_ends_and_next_stage_opens(): void
    {
        $workflow = app(WorkflowService::class);
        $assignment = $workflow->assign($this->requirement, $this->qiyas, $this->programManager, $this->deptA, null, '2026-12-01', null, null, null);
        $draft = $workflow->getOrCreateDraft($assignment, $this->deptAEmployee);
        $workflow->addFile($draft, UploadedFile::fake()->create('e.pdf', 10, 'application/pdf'), $this->deptAEmployee);
        $workflow->submit($draft, $this->deptAEmployee, null);

        $this->assertDatabaseHas('sla_instances', ['requirement_assignment_id' => $assignment->id, 'stage' => 'employee', 'status' => 'completed_within_sla']);
        $this->assertDatabaseHas('sla_instances', ['requirement_assignment_id' => $assignment->id, 'stage' => 'department_manager', 'status' => 'active']);
    }

    public function test_scheduled_command_detects_breach_and_does_not_duplicate_on_second_run(): void
    {
        $assignment = app(WorkflowService::class)->assign(
            $this->requirement, $this->qiyas, $this->programManager, $this->deptA, null, '2026-12-01', null, null, null,
        );

        SlaInstance::where('requirement_assignment_id', $assignment->id)->where('stage', 'employee')
            ->update(['started_at' => now()->subDays(10), 'due_at' => now()->subDays(3)]);

        $this->artisan('compliance:process-sla')->assertExitCode(0);
        $this->assertDatabaseHas('sla_instances', ['requirement_assignment_id' => $assignment->id, 'stage' => 'employee', 'status' => 'breached']);

        $breachNotifications = NotificationLog::where('event_type', 'sla_breached')->count();

        // Running it again must not queue a second breach notification for the same instance.
        $this->artisan('compliance:process-sla')->assertExitCode(0);
        $this->assertEquals($breachNotifications, NotificationLog::where('event_type', 'sla_breached')->count());
    }

    public function test_reviewer_delay_is_not_attributed_to_employee(): void
    {
        $workflow = app(WorkflowService::class);
        $assignment = $workflow->assign($this->requirement, $this->qiyas, $this->programManager, $this->deptA, null, '2026-12-01', null, null, null);
        $draft = $workflow->getOrCreateDraft($assignment, $this->deptAEmployee);
        $workflow->addFile($draft, UploadedFile::fake()->create('e.pdf', 10, 'application/pdf'), $this->deptAEmployee);
        $submission = $workflow->submit($draft, $this->deptAEmployee, null);

        $employeeInstance = SlaInstance::where('requirement_assignment_id', $assignment->id)->where('stage', 'employee')->first();
        $managerInstance = SlaInstance::where('requirement_assignment_id', $assignment->id)->where('stage', 'department_manager')->first();

        // The employee's own SLA instance is closed and scoped only to their
        // own turnaround; the manager's separate instance is what measures
        // review-stage delay — they are never the same record.
        $this->assertNotEquals($employeeInstance->id, $managerInstance->id);
        $this->assertEquals('completed_within_sla', $employeeInstance->fresh()->status);
        $this->assertEquals('active', $managerInstance->status);
    }
}
