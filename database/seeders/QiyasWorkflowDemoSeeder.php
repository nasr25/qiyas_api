<?php

namespace Database\Seeders;

use App\Models\AssessmentCycle;
use App\Models\ComplianceProgram;
use App\Models\Department;
use App\Models\SlaInstance;
use App\Models\Standard;
use App\Models\User;
use App\Services\ExtensionService;
use App\Services\WorkflowService;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Drives real state transitions through WorkflowService/ExtensionService
 * (not hand-crafted rows) to produce one RequirementAssignment per Phase 2
 * workflow status, plus SLA warning/breach and extension examples, so
 * Quick Login accounts have something meaningful to review immediately.
 * Idempotent: skipped entirely if demo assignments already exist.
 */
class QiyasWorkflowDemoSeeder extends Seeder
{
    public function run(WorkflowService $workflow, ExtensionService $extensions): void
    {
        $program = ComplianceProgram::where('code', 'QIYAS')->first();
        if (! $program) {
            return;
        }

        if (DB::table('requirement_assignments')->where('compliance_program_id', $program->id)->exists()) {
            $this->command?->info('Qiyas workflow demo data already present — skipping.');

            return;
        }

        $cycle = AssessmentCycle::where('compliance_program_id', $program->id)->where('is_current', true)->first();
        if (! $cycle) {
            return;
        }

        $superAdmin = User::where('username', 'superadmin')->first();
        $programManager = User::where('username', 'qiyas_admin')->first();
        $auditor = User::where('username', 'auditor_1')->first();
        $itDept = Department::where('name_en', 'Information Technology')->first();
        $hrDept = Department::where('name_en', 'Human Resources')->first();
        $itEmployee = User::where('username', 'it_employee_1')->first();
        $hrEmployee = User::where('username', 'hr_employee_1')->first();

        if (! $programManager || ! $auditor || ! $itDept || ! $hrDept || ! $itEmployee || ! $hrEmployee) {
            $this->command?->warn('Required Phase 1 test accounts not found — skipping Qiyas workflow demo data.');

            return;
        }

        // Standards not already tied to a demo assignment; distinct so each
        // scenario gets its own requirement.
        // Prefer standards from the current cycle, but the demo trial cycle
        // may not have enough — fall back to any of the program's cycles so
        // seeding still succeeds. Each assignment correctly records its own
        // requirement's actual cycle_id regardless of which cycle it came from.
        $standards = Standard::where('compliance_program_id', $program->id)
            ->where('cycle_id', $cycle->id)
            ->inRandomOrder()->limit(12)->get();

        if ($standards->count() < 12) {
            $standards = Standard::where('compliance_program_id', $program->id)
                ->inRandomOrder()->limit(12)->get();
        }

        if ($standards->count() < 12) {
            $this->command?->warn('Not enough standards in the program to seed all Qiyas workflow demo scenarios.');

            return;
        }

        [
            $sAssigned, $sDraft, $sPendingDm, $sPendingAuditor, $sPendingPm, $sReturned,
            $sApproved, $sOverdue, $sExtPending, $sExtApproved, $sSlaWarning, $sSlaBreach,
        ] = $standards->all();

        $assign = fn (Standard $s, Department $d, ?User $emp, ?string $due = null) => $workflow->assign(
            $s, $program, $programManager, $d, $emp, $due ?? now()->addDays(14)->toDateString(), 'medium', null, null,
        );

        $upload = fn ($submission, User $emp) => $workflow->addFile($submission, UploadedFile::fake()->create('evidence.pdf', 100, 'application/pdf'), $emp);

        // 1. assigned — no submission started yet.
        $assign($sAssigned, $itDept, $itEmployee);

        // 2. draft
        $a = $assign($sDraft, $itDept, $itEmployee);
        $sub = $workflow->getOrCreateDraft($a, $itEmployee);
        $upload($sub, $itEmployee);

        // 3. pending_department_manager
        $a = $assign($sPendingDm, $itDept, $itEmployee);
        $sub = $workflow->getOrCreateDraft($a, $itEmployee);
        $upload($sub, $itEmployee);
        $workflow->submit($sub, $itEmployee, 'Initial evidence for review.');

        // 4. pending_auditor
        $a = $assign($sPendingAuditor, $hrDept, $hrEmployee);
        $sub = $workflow->getOrCreateDraft($a, $hrEmployee);
        $upload($sub, $hrEmployee);
        $sub = $workflow->submit($sub, $hrEmployee, null);
        $workflow->approve($sub, $this->deptManager($hrDept), 'department_manager', 'department-manager', 'Looks complete.');

        // 5. pending_program_manager
        $a = $assign($sPendingPm, $itDept, $itEmployee);
        $sub = $workflow->getOrCreateDraft($a, $itEmployee);
        $upload($sub, $itEmployee);
        $sub = $workflow->submit($sub, $itEmployee, null);
        $sub = $workflow->approve($sub, $this->deptManager($itDept), 'department_manager', 'department-manager', null);
        $workflow->approve($sub, $auditor, 'auditor', 'auditor', 'Evidence verified.');

        // 6. returned_for_revision (rejected by department manager)
        $a = $assign($sReturned, $hrDept, $hrEmployee);
        $sub = $workflow->getOrCreateDraft($a, $hrEmployee);
        $upload($sub, $hrEmployee);
        $sub = $workflow->submit($sub, $hrEmployee, null);
        $workflow->reject($sub, $this->deptManager($hrDept), 'department_manager', 'department-manager', 'Missing signed cover page.', null);

        // 7. approved (final)
        $a = $assign($sApproved, $itDept, $itEmployee);
        $sub = $workflow->getOrCreateDraft($a, $itEmployee);
        $upload($sub, $itEmployee);
        $sub = $workflow->submit($sub, $itEmployee, null);
        $sub = $workflow->approve($sub, $this->deptManager($itDept), 'department_manager', 'department-manager', null);
        $sub = $workflow->approve($sub, $auditor, 'auditor', 'auditor', null);
        $workflow->approve($sub, $programManager, 'program_manager', 'program-manager', 'Final approval granted.');

        // 8. overdue — assigned with a due date in the past, still in draft.
        $a = $assign($sOverdue, $hrDept, $hrEmployee, now()->subDays(5)->toDateString());
        $workflow->getOrCreateDraft($a, $hrEmployee);

        // 9. pending extension request
        $a = $assign($sExtPending, $itDept, $itEmployee);
        $extensions->request($a, $itEmployee, now()->addDays(21)->toDateString(), 'Additional time needed to collect signed evidence from stakeholders.');

        // 10. approved extension (original due date preserved, effective date moved)
        $a = $assign($sExtApproved, $hrDept, $hrEmployee);
        $ext = $extensions->request($a, $hrEmployee, now()->addDays(30)->toDateString(), 'Awaiting external audit confirmation.');
        $extensions->decide($ext, $auditor, 'approved', null, 'Reasonable justification provided.');

        // SLA warning / breach: backdate the started_at/due_at of the
        // opened SLA instance so ProcessSlaCommand's threshold/overdue
        // checks fire realistically without waiting for real time to pass.
        $warningAssignment = $assign($sSlaWarning, $itDept, $itEmployee);
        SlaInstance::where('requirement_assignment_id', $warningAssignment->id)->where('stage', 'employee')
            ->update(['started_at' => now()->subDays(4), 'due_at' => now()->addHours(2)]);

        $breachAssignment = $assign($sSlaBreach, $hrDept, $hrEmployee);
        SlaInstance::where('requirement_assignment_id', $breachAssignment->id)->where('stage', 'employee')
            ->update(['started_at' => now()->subDays(10), 'due_at' => now()->subDays(3)]);

        $this->command?->info('Qiyas Phase 2 workflow demo data seeded (12 assignments covering every status + SLA + extension scenarios).');
    }

    private function deptManager(Department $department): User
    {
        return User::where('department_id', $department->id)
            ->whereHas('roles', fn ($q) => $q->where('name', 'coordinator'))
            ->firstOrFail();
    }
}
