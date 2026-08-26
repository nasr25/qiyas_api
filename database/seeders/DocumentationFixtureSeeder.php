<?php

namespace Database\Seeders;

use App\Models\AssessmentCycle;
use App\Models\ComplianceNode;
use App\Models\ComplianceProgram;
use App\Models\Department;
use App\Models\EvidenceFile;
use App\Models\ExtensionRequest;
use App\Models\ProgramUserRole;
use App\Models\User;
use App\Notifications\WorkflowEventNotification;
use App\Services\HierarchyDefinitionService;
use App\Services\WorkflowService;
use Database\Seeders\Concerns\NonProductionSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fixture for the illustrated Arabic user-guide screenshot suite
 * (tests/e2e/documentation/qiyas).
 *
 * Replaces the retired QiyasDocumentationSeeder, which authored legacy
 * `Standard` rows. Content is now ComplianceNodes built from Qiyas's own
 * structure, so the guide illustrates the product as it actually works.
 *
 * Accounts use neutral, artificial names — never a real person — and exist
 * only in documentation/testing environments.
 */
class DocumentationFixtureSeeder extends Seeder
{
    use NonProductionSeeder;

    private const PASSWORD = 'Password123!';

    /** username => [spatie role, program role, needs a department] */
    private const ACCOUNTS = [
        'qiyas_pm_docs' => ['qiyas-admin', 'program-manager', false],
        'qiyas_dm_docs' => ['coordinator', 'department-manager', true],
        'qiyas_auditor_docs' => ['auditor', 'auditor', false],
        'qiyas_employee_docs' => ['employee', 'employee', true],
        'qiyas_exec_docs' => ['executive', null, false],
    ];

    public function run(): void
    {
        $this->guardAgainstProduction();

        $program = ComplianceProgram::where('code', 'QIYAS')->first();
        $admin = User::where('username', 'superadmin')->first();
        if (! $program || ! $admin) {
            return;
        }

        $structures = app(HierarchyDefinitionService::class);
        if (! $structures->activeDefinition($program)) {
            $this->command?->warn('QIYAS has no active structure — run HierarchyStructureSeeder first.');

            return;
        }

        $department = Department::firstOrCreate(
            ['name_en' => 'Documentation Department'],
            ['name_ar' => 'إدارة التوثيق', 'is_active' => true],
        );

        $users = $this->accounts($program, $department);
        $cycle = $this->cycle($program, $admin, $structures);
        $leaves = $this->content($program, $cycle, $structures, $admin);

        $this->workflowStates($program, $leaves, $department, $users);

        $this->command?->info(sprintf(
            '  Documentation fixture: %d node(s), %d account(s), department "%s".',
            ComplianceNode::where('program_cycle_id', $cycle->id)->count(),
            count($users), $department->name_en,
        ));
    }

    /** @return array<string, User> */
    private function accounts(ComplianceProgram $program, Department $department): array
    {
        $users = [];

        foreach (self::ACCOUNTS as $username => [$spatieRole, $programRole, $needsDepartment]) {
            $user = User::firstOrCreate(['username' => $username], [
                'name' => ucwords(str_replace('_', ' ', $username)),
                'email' => "{$username}@qiyas.local",
                'password' => self::PASSWORD,
                'auth_type' => 'local',
                'department_id' => $needsDepartment ? $department->id : null,
                'is_active' => true,
                'must_change_password' => false,
                'locale' => 'ar',
            ]);

            if (! $user->hasRole($spatieRole)) {
                $user->assignRole($spatieRole);
            }
            if ($programRole) {
                ProgramUserRole::firstOrCreate(
                    ['compliance_program_id' => $program->id, 'user_id' => $user->id, 'role_key' => $programRole],
                    ['department_id' => $needsDepartment ? $department->id : null, 'is_active' => true, 'assigned_at' => now()],
                );
            }

            $users[$username] = $user;
        }

        return $users;
    }

    private function cycle(ComplianceProgram $program, User $admin, HierarchyDefinitionService $structures): AssessmentCycle
    {
        return AssessmentCycle::firstOrCreate(
            ['compliance_program_id' => $program->id, 'name' => 'دورة قياس التجريبية 2026'],
            [
                'structure_version_id' => $structures->currentStructureVersion($program)?->id,
                'year' => 2026,
                'start_date' => now()->startOfYear()->toDateString(),
                'end_date' => now()->endOfYear()->toDateString(),
                'status' => 'active',
                'is_current' => false,
                'created_by' => $admin->id,
            ],
        );
    }

    /** @return array<int, ComplianceNode> the assignable leaves */
    private function content(ComplianceProgram $program, AssessmentCycle $cycle, HierarchyDefinitionService $structures, User $admin): array
    {
        // Work happens where evidence is allowed: a level may be assignable
        // without being evidence-bearing, and this fixture submits evidence.
        $workLevel = $this->workLevel($structures, $program);

        if (ComplianceNode::where('program_cycle_id', $cycle->id)->exists()) {
            return ComplianceNode::where('program_cycle_id', $cycle->id)
                ->where('hierarchy_level_id', $workLevel?->id)->get()->all();
        }

        $levels = collect($structures->levels($program))->values();
        $versionId = $structures->currentStructureVersion($program)?->id;

        $parents = [null];
        foreach ($levels as $depth => $level) {
            $next = [];
            foreach ($parents as $parent) {
                // Enough breadth that every workflow state below has its own
                // item to illustrate, while staying readable in a screenshot.
                for ($i = 1; $i <= ($depth === 0 ? 6 : ($depth === 1 ? 4 : 1)); $i++) {
                    $code = $parent ? "{$parent->code}.{$i}" : "DOC-{$i}";
                    $next[] = ComplianceNode::create([
                        'compliance_program_id' => $program->id,
                        'program_cycle_id' => $cycle->id,
                        'hierarchy_level_id' => $level->id,
                        'structure_version_id' => $versionId,
                        'parent_id' => $parent?->id,
                        'node_type' => $level->key,
                        'level' => $depth,
                        'code' => $code,
                        'name_ar' => "{$level->name_ar} توضيحي {$code}",
                        'name_en' => "Illustrative {$level->name_en} {$code}",
                        'sort_order' => $i,
                        'is_assessable' => $level->is_assessable,
                        'status' => 'active',
                        'created_by' => $admin->id, 'updated_by' => $admin->id,
                    ]);
                }
            }
            $parents = $next;

            if ($workLevel && $level->id === $workLevel->id) {
                $workNodes = $next;
            }
        }

        return $workNodes ?? $parents;
    }

    /** The level that is both assignable and evidence-bearing. */
    private function workLevel(HierarchyDefinitionService $structures, ComplianceProgram $program)
    {
        $levels = collect($structures->levels($program));

        return $levels->first(fn ($l) => $l->is_assignable && $l->accepts_evidence)
            ?? $levels->firstWhere('is_assignable', true);
    }

    /**
     * Drives assignments into an EXPLICIT spread of workflow states.
     *
     * Every guide screen consumes an item — the department manager's guide
     * shows one approval and one rejection, the auditor's the same — so the
     * states are planned per index rather than accumulated with thresholds.
     * A cumulative approach left later screens with an empty queue.
     */
    private function workflowStates(ComplianceProgram $program, array $leaves, Department $department, array $users): void
    {
        $workflow = app(WorkflowService::class);
        $pm = $users['qiyas_pm_docs'];
        $employee = $users['qiyas_employee_docs'];
        $deptManager = $users['qiyas_dm_docs'];
        $auditor = $users['qiyas_auditor_docs'];

        // index => target state. Anything beyond the plan is left unassigned,
        // which the assignment screen needs in its picker.
        // Each guide screen consumes an item, and several screens act on
        // one (approve, reject), so every reviewer queue is seeded with
        // more than it needs rather than exactly enough.
        $plan = [
            1 => 'assigned',
            2 => 'draft',
            3 => 'draft',
            4 => 'pending_department_manager',
            5 => 'pending_department_manager',
            6 => 'pending_department_manager',
            7 => 'pending_department_manager',
            8 => 'pending_auditor',
            9 => 'pending_auditor',
            10 => 'pending_auditor',
            11 => 'pending_auditor',
            12 => 'pending_program_manager',
            13 => 'pending_program_manager',
            14 => 'pending_program_manager',
            15 => 'returned_for_revision',
            16 => 'returned_for_revision',
            17 => 'extension',
            18 => 'extension',
            19 => 'extension',
        ];

        foreach (array_values($leaves) as $i => $leaf) {
            $target = $plan[$i] ?? null;
            if ($target === null) {
                continue; // deliberately unassigned
            }

            try {
                $assignment = $workflow->assign(
                    $leaf, $program, $pm, $department, $employee,
                    now()->addMonths(2)->toDateString(), 'normal',
                    'يرجى رفع مستندات الإثبات المطلوبة.', 'Please upload the required evidence.',
                );

                if ($target === 'assigned') {
                    continue;
                }

                if ($target === 'extension') {
                    ExtensionRequest::firstOrCreate(
                        ['requirement_assignment_id' => $assignment->id, 'status' => 'pending'],
                        [
                            'compliance_program_id' => $program->id,
                            'requested_by' => $employee->id,
                            'requested_date' => now()->addDays(21)->toDateString(),
                            'reason' => 'نحتاج وقتًا إضافيًا لاستكمال جمع المستندات المطلوبة.',
                        ],
                    );

                    continue;
                }

                $submission = $workflow->getOrCreateDraft($assignment, $employee);
                $this->attachEvidenceFile($submission, $employee);

                if ($target === 'draft') {
                    continue;
                }

                $submission = $workflow->submit($submission->fresh(), $employee, 'تم رفع المستندات.');

                if ($target === 'returned_for_revision') {
                    $workflow->reject($submission->fresh(), $deptManager, 'department_manager', 'department-manager',
                        'المستندات غير مكتملة، يرجى إعادة الرفع.', null);

                    continue;
                }

                if (in_array($target, ['pending_auditor', 'pending_program_manager'], true)) {
                    $submission = $workflow->approve($submission->fresh(), $deptManager, 'department_manager', 'department-manager', 'مكتمل.');
                }
                if ($target === 'pending_program_manager') {
                    $workflow->approve($submission->fresh(), $auditor, 'auditor', 'auditor', 'تم التحقق.');
                }
            } catch (Throwable $e) {
                $this->command?->warn("  documentation fixture (index {$i}): {$e->getMessage()}");
            }
        }

        $this->notifications($employee);
        $this->notifications($deptManager);
    }

    /** The workflow refuses to submit without at least one file. */
    private function attachEvidenceFile($submission, User $employee): void
    {
        EvidenceFile::firstOrCreate(
            ['evidence_submission_id' => $submission->id, 'original_name' => 'evidence.pdf'],
            [
                'stored_name' => "docs-{$submission->id}.pdf",
                'storage_path' => "evidence/docs/docs-{$submission->id}.pdf",
                'mime_type' => 'application/pdf',
                'file_size' => 10240,
                'file_hash' => hash('sha256', "docs-evidence-{$submission->id}"),
                'uploaded_by' => $employee->id,
                'uploaded_at' => now(),
                'is_active' => true,
            ],
        );
    }

    /**
     * Unread notifications for the notification-centre screenshots. Seeded
     * for both the employee and the department manager, because the guide
     * illustrates the centre from each of their perspectives.
     */
    private function notifications(User $recipient): void
    {
        if ($recipient->notifications()->count() > 0) {
            return;
        }

        foreach ([
            ['requirement_assigned', 'تم إسناد متطلب جديد إلى إدارتك.', 'A new requirement was assigned to your department.'],
            ['evidence_returned', 'أُعيد أحد المستندات للتصحيح.', 'A submission was returned for revision.'],
            ['deadline_approaching', 'يقترب موعد استحقاق أحد المتطلبات.', 'A requirement deadline is approaching.'],
        ] as [$type, $ar, $en]) {
            $recipient->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => WorkflowEventNotification::class,
                'data' => ['type' => $type, 'message_ar' => $ar, 'message_en' => $en],
                'read_at' => null,
            ]);
        }
    }
}
