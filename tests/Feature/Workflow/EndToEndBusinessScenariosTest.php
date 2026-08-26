<?php

namespace Tests\Feature\Workflow;

use App\Models\AuditLog;
use App\Models\SlaInstance;
use App\Notifications\WorkflowEventNotification;
use App\Services\WorkflowService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;

/**
 * Covers the Phase 3 audit's numbered business scenarios that were not
 * already exercised by the Phase 2 suite: the full happy-path metrics/
 * notification/audit trail (Scenario 1), decision immutability across a
 * rejection-then-resubmit cycle (Scenarios 2-4), extension rejection
 * leaving the due date untouched (Scenario 6), employee-attributed SLA
 * delay (Scenario 7), delivery-overdue as a status-independent condition
 * (Scenario 9), and per-recipient notification isolation (Scenario 13).
 * Scenarios 5, 8, 10, 11, 12 and 14 already had dedicated coverage
 * elsewhere in tests/Feature/Workflow — see docs/qiyas-known-issues.md for
 * the full scenario-to-test cross-reference.
 */
class EndToEndBusinessScenariosTest extends WorkflowTestCase
{
    // ─── Scenario 1: successful completion, end to end ─────────────────────

    public function test_scenario1_full_approval_updates_metrics_notifies_correctly_and_leaves_a_complete_audit_trail(): void
    {
        Notification::fake();
        $workflow = app(WorkflowService::class);

        $assignment = $workflow->assign(
            $this->requirement, $this->qiyas, $this->programManager, $this->deptA, $this->deptAEmployee, '2026-12-01', null, null, null,
        );

        $draft = $workflow->getOrCreateDraft($assignment, $this->deptAEmployee);
        $workflow->addFile($draft, UploadedFile::fake()->create('evidence.pdf', 100, 'application/pdf'), $this->deptAEmployee);
        $submitted = $workflow->submit($draft, $this->deptAEmployee, null);

        $workflow->approve($submitted, $this->deptAManager, 'department_manager', 'department-manager', 'ok');
        $submitted->refresh();
        $workflow->approve($submitted, $this->auditor, 'auditor', 'auditor', 'ok');
        $submitted->refresh();
        $final = $workflow->approve($submitted, $this->programManager, 'program_manager', 'program-manager', 'ok');

        $this->assertSame('approved', $final->status);
        $this->assertSame('completed', $assignment->fresh()->status);

        // Compliance metric: the dashboard's approved counter reflects it.
        $dashboard = $this->getJson('/api/v1/programs/QIYAS/dashboards/program-manager', $this->authHeader($this->programManager))
            ->assertOk()->json('data');
        $this->assertGreaterThanOrEqual(1, $dashboard['status_counts']['approved'] ?? 0);

        // Every stage transition left exactly one decision record — a
        // complete, queryable history, not just a final status.
        $stages = $final->decisions()->pluck('stage')->all();
        $this->assertEqualsCanonicalizing(['department_manager', 'auditor', 'program_manager'], $stages);
        $this->assertTrue($final->decisions()->where('decision', 'approved')->count() === 3);

        // Each transition is independently audit-logged (not just the workflow_events timeline).
        $this->assertGreaterThanOrEqual(3, AuditLog::where('compliance_program_id', $this->qiyas->id)->count());

        // Only the actually-relevant recipients were notified: the assigned
        // employee saw the assignment, and program-manager-final-approval
        // notifies the whole stakeholder chain — but Department B, which was
        // never involved, received nothing about this requirement.
        Notification::assertSentTo($this->deptAEmployee, WorkflowEventNotification::class);
        Notification::assertNotSentTo($this->deptBEmployee, WorkflowEventNotification::class);
    }

    // ─── Scenario 2/3: rejection immutability + resubmission restarts fresh ─

    public function test_scenario3_auditor_rejection_leaves_department_managers_prior_decision_immutable_and_resubmission_runs_a_full_new_cycle(): void
    {
        $workflow = app(WorkflowService::class);
        $assignment = $workflow->assign($this->requirement, $this->qiyas, $this->programManager, $this->deptA, null, '2026-12-01', null, null, null);

        $v1 = $workflow->getOrCreateDraft($assignment, $this->deptAEmployee);
        $workflow->addFile($v1, UploadedFile::fake()->create('e1.pdf', 10, 'application/pdf'), $this->deptAEmployee);
        $v1 = $workflow->submit($v1, $this->deptAEmployee, null);

        $v1 = $workflow->approve($v1, $this->deptAManager, 'department_manager', 'department-manager', null);
        $deptManagerDecisionId = $v1->decisions()->where('stage', 'department_manager')->first()->id;

        $v1 = $workflow->reject($v1, $this->auditor, 'auditor', 'auditor', 'missing signature', null);

        $this->assertSame('returned_for_revision', $v1->status);
        $this->assertSame('employee', $v1->current_stage);

        // The Department Manager's earlier approval must still exist, unchanged.
        $this->assertDatabaseHas('workflow_decisions', ['id' => $deptManagerDecisionId, 'stage' => 'department_manager', 'decision' => 'approved']);

        // Employee corrects and resubmits — a NEW version, not an edit of v1.
        $v2 = $workflow->getOrCreateDraft($assignment, $this->deptAEmployee);
        $this->assertNotSame($v1->id, $v2->id);
        $this->assertSame(2, $v2->version_number);

        $workflow->addFile($v2, UploadedFile::fake()->create('e2.pdf', 10, 'application/pdf'), $this->deptAEmployee);
        $v2 = $workflow->submit($v2, $this->deptAEmployee, null);

        // Must restart at the Department Manager, not skip back to the Auditor.
        $this->assertSame('pending_department_manager', $v2->status);

        // v1's history (including the auditor's rejection) is still queryable.
        $v1->refresh();
        $this->assertSame(2, $v1->decisions()->count());
        $this->assertDatabaseHas('workflow_decisions', ['evidence_submission_id' => $v1->id, 'stage' => 'auditor', 'decision' => 'rejected']);

        // The new cycle gets its own fresh decisions once reviewed again.
        $v2 = $workflow->approve($v2, $this->deptAManager, 'department_manager', 'department-manager', null);
        $this->assertSame(1, $v2->decisions()->count());
    }

    public function test_scenario4_program_manager_rejection_returns_to_employee_and_resubmission_starts_a_complete_new_review_cycle(): void
    {
        $workflow = app(WorkflowService::class);
        $assignment = $workflow->assign($this->requirement, $this->qiyas, $this->programManager, $this->deptA, null, '2026-12-01', null, null, null);

        $v1 = $workflow->getOrCreateDraft($assignment, $this->deptAEmployee);
        $workflow->addFile($v1, UploadedFile::fake()->create('e1.pdf', 10, 'application/pdf'), $this->deptAEmployee);
        $v1 = $workflow->submit($v1, $this->deptAEmployee, null);
        $v1 = $workflow->approve($v1, $this->deptAManager, 'department_manager', 'department-manager', null);
        $v1 = $workflow->approve($v1, $this->auditor, 'auditor', 'auditor', null);
        $v1 = $workflow->reject($v1, $this->programManager, 'program_manager', 'program-manager', 'incomplete evidence', null);

        $this->assertSame('returned_for_revision', $v1->status);
        $this->assertSame('employee', $v1->current_stage);

        $v2 = $workflow->getOrCreateDraft($assignment, $this->deptAEmployee);
        $workflow->addFile($v2, UploadedFile::fake()->create('e2.pdf', 10, 'application/pdf'), $this->deptAEmployee);
        $v2 = $workflow->submit($v2, $this->deptAEmployee, null);
        $this->assertSame('pending_department_manager', $v2->status);

        // Full new cycle: department manager -> auditor -> program manager again, each producing its own decision.
        $v2 = $workflow->approve($v2, $this->deptAManager, 'department_manager', 'department-manager', null);
        $v2 = $workflow->approve($v2, $this->auditor, 'auditor', 'auditor', null);
        $v2 = $workflow->approve($v2, $this->programManager, 'program_manager', 'program-manager', null);

        $this->assertSame('approved', $v2->status);
        $this->assertEqualsCanonicalizing(['department_manager', 'auditor', 'program_manager'], $v2->decisions()->pluck('stage')->all());
    }

    // ─── Scenario 6: extension rejected — due date must not move ───────────

    public function test_scenario6_rejected_extension_leaves_due_date_unchanged_and_is_audit_logged(): void
    {
        $workflow = app(WorkflowService::class);
        $assignment = $workflow->assign($this->requirement, $this->qiyas, $this->programManager, $this->deptA, $this->deptAEmployee, now()->addDays(5)->toDateString(), null, null, null);
        $originalDue = $assignment->effective_due_date->toDateString();

        $extensionRequest = $this->postJson("/api/v1/programs/QIYAS/assignments/{$assignment->id}/extension-requests", [
            'requested_due_date' => now()->addDays(20)->toDateString(),
            'reason' => 'need more time',
        ], $this->authHeader($this->deptAEmployee))->assertCreated()->json('data');

        $this->postJson("/api/v1/programs/QIYAS/reviews/auditor/extension-requests/{$extensionRequest['id']}/reject", [
            'reason' => 'insufficient justification',
        ], $this->authHeader($this->auditor))->assertOk();

        $assignment->refresh();
        $this->assertSame($originalDue, $assignment->effective_due_date->toDateString());
        $this->assertDatabaseHas('audit_logs', ['action' => 'workflow.extension_rejected']);
    }

    // ─── Scenario 7: employee delay attribution ─────────────────────────────

    public function test_scenario7_employee_submission_delay_is_attributed_to_the_employee_not_a_reviewer(): void
    {
        $workflow = app(WorkflowService::class);
        $assignment = $workflow->assign($this->requirement, $this->qiyas, $this->programManager, $this->deptA, $this->deptAEmployee, '2026-12-01', null, null, null);

        $instance = SlaInstance::where('requirement_assignment_id', $assignment->id)->where('stage', 'employee')->first();
        $this->assertNotNull($instance);
        $this->assertSame($this->deptAEmployee->id, $instance->responsible_user_id);

        // Force the instance overdue and let the scheduled command detect it.
        $instance->update(['due_at' => now()->subDay()]);
        $this->artisan('compliance:process-sla');

        $instance->refresh();
        $this->assertSame('breached', $instance->status);
        $this->assertSame($this->deptAEmployee->id, $instance->responsible_user_id);

        // The Department Manager's own dashboard must show this employee (not a reviewer) as behind.
        $dashboard = $this->getJson('/api/v1/programs/QIYAS/dashboards/department-manager', $this->authHeader($this->deptAManager))
            ->assertOk()->json('data');
        $employeeRow = collect($dashboard['employee_workload'])->first(fn ($row) => ($row['employee']['id'] ?? null) === $this->deptAEmployee->id);
        $this->assertNotNull($employeeRow);
    }

    // ─── Scenario 9: delivery-overdue is a separate condition from status ──

    public function test_scenario9_overdue_is_a_calculated_condition_independent_of_workflow_status(): void
    {
        $workflow = app(WorkflowService::class);
        $assignment = $workflow->assign($this->requirement, $this->qiyas, $this->programManager, $this->deptA, null, now()->subDay()->toDateString(), null, null, null);

        // The assignment/workflow status is still simply "active" with no
        // submission yet — never rewritten to a fake "overdue" status; overdue
        // is purely a derived, separately-reported condition (see below).
        $this->assertSame('active', $assignment->fresh()->status);
        $this->assertSame('assigned', $assignment->fresh()->displayStatus());

        $overdue = $this->getJson('/api/v1/programs/QIYAS/reports/overdue-requirements', $this->authHeader($this->programManager))
            ->assertOk()->json('data');

        $this->assertNotEmpty(collect($overdue)->firstWhere('department', $this->deptA->name));
    }

    // ─── Scenario 13: notification isolation between recipients ────────────

    public function test_scenario13_reading_or_deleting_one_recipients_notification_does_not_affect_the_others(): void
    {
        $workflow = app(WorkflowService::class);
        $assignment = $workflow->assign($this->requirement, $this->qiyas, $this->programManager, $this->deptA, null, '2026-12-01', null, null, null);

        // Same business event, delivered to two different recipients as two
        // independent in-app notification rows (exactly what
        // WorkflowService::notify()/NotificationService dispatch to every
        // recipient of a multi-recipient event, e.g. program_manager_approved
        // going to both the department and the auditor).
        $event = new WorkflowEventNotification('requirement_assigned', $assignment);
        $this->deptAManager->notify($event);
        $this->auditor->notify($event);

        $managerNotificationId = $this->deptAManager->notifications()->first()->id;
        $auditorNotificationId = $this->auditor->notifications()->first()->id;

        $this->postJson("/api/v1/notifications/{$managerNotificationId}/read", [], $this->authHeader($this->deptAManager))->assertOk();

        $this->assertNotNull($this->deptAManager->notifications()->find($managerNotificationId)->read_at);
        $this->assertNull($this->auditor->notifications()->find($auditorNotificationId)->read_at);

        $this->deleteJson("/api/v1/notifications/{$managerNotificationId}", [], $this->authHeader($this->deptAManager))->assertOk();
        $this->assertNull($this->deptAManager->notifications()->find($managerNotificationId));
        $this->assertNotNull($this->auditor->notifications()->find($auditorNotificationId));
    }
}
