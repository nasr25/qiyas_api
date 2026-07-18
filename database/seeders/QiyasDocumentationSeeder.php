<?php

namespace Database\Seeders;

use App\Models\AssessmentCycle;
use App\Models\ComplianceProgram;
use App\Models\Department;
use App\Models\RequirementAssignment;
use App\Models\Standard;
use App\Models\User;
use App\Services\ExtensionService;
use App\Services\WorkflowService;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Documentation-only data for the Qiyas illustrated user guide. Entirely
 * artificial: neutral role-based names, a dedicated cycle/department, and a
 * standard number prefix (QIYAS-TEST-) that never collides with the real DGA
 * catalog. Never run against a shared/dev database — intended for a
 * dedicated, isolated documentation database only. Idempotent: skipped if
 * the documentation department already exists.
 *
 * State transitions are driven through WorkflowService/ExtensionService
 * (never hand-inserted rows), matching the pattern in
 * QiyasWorkflowDemoSeeder, so every screenshot reflects a state the
 * application itself actually produced.
 */
class QiyasDocumentationSeeder extends Seeder
{
    private const PASSWORD = 'Password123!';

    public function run(WorkflowService $workflow, ExtensionService $extensions): void
    {
        $program = ComplianceProgram::where('code', 'QIYAS')->first();
        if (! $program) {
            $this->command?->warn('QIYAS program not found — run the base seeders first.');

            return;
        }

        if (Department::where('name_ar', 'الإدارة التجريبية')->exists()) {
            $this->command?->info('Qiyas documentation data already present — skipping.');

            return;
        }

        $department = Department::create([
            'name_ar' => 'الإدارة التجريبية',
            'name_en' => 'Test Department',
            'description' => 'إدارة اصطناعية لأغراض توثيق دليل المستخدم فقط.',
            'is_active' => true,
        ]);

        $accounts = [
            'qiyas_pm_docs' => ['مدير برنامج قياس التجريبي', 'qiyas-admin', null],
            'qiyas_dm_docs' => ['مدير الإدارة التجريبي', 'coordinator', $department->id],
            'qiyas_auditor_docs' => ['المدقق التجريبي', 'auditor', null],
            'qiyas_employee_docs' => ['الموظف التجريبي', 'employee', $department->id],
            'qiyas_exec_docs' => ['المشاهد التنفيذي التجريبي', 'executive', null],
        ];

        $users = [];
        foreach ($accounts as $username => [$name, $roleName, $departmentId]) {
            $user = User::updateOrCreate(
                ['username' => $username],
                [
                    'name' => $name,
                    'email' => "{$username}@example.test",
                    'password' => Hash::make(self::PASSWORD),
                    'department_id' => $departmentId,
                    'auth_type' => 'local',
                    'locale' => 'ar',
                    'is_active' => true,
                    'must_change_password' => false,
                ]
            );
            $user->syncRoles([Role::where('name', $roleName)->where('guard_name', 'api')->firstOrFail()]);
            $users[$username] = $user;
        }

        [$programManager, $departmentManager, $auditor, $employee, $executive] = [
            $users['qiyas_pm_docs'], $users['qiyas_dm_docs'], $users['qiyas_auditor_docs'],
            $users['qiyas_employee_docs'], $users['qiyas_exec_docs'],
        ];

        // Program-role rows — the same mapping ProgramMigrationService applies
        // for real accounts, written directly since these are new users.
        DB::table('program_user_roles')->insert([
            ['user_id' => $programManager->id, 'compliance_program_id' => $program->id, 'role_key' => 'program-manager', 'department_id' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $departmentManager->id, 'compliance_program_id' => $program->id, 'role_key' => 'department-manager', 'department_id' => $department->id, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $auditor->id, 'compliance_program_id' => $program->id, 'role_key' => 'auditor', 'department_id' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $employee->id, 'compliance_program_id' => $program->id, 'role_key' => 'employee', 'department_id' => $department->id, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $cycle = AssessmentCycle::create([
            'compliance_program_id' => $program->id,
            'name' => 'دورة قياس التجريبية 2026',
            'name_ar' => 'دورة قياس التجريبية 2026',
            'name_en' => 'Qiyas Documentation Test Cycle 2026',
            'year' => 2026,
            'start_date' => now()->subMonths(2)->toDateString(),
            'end_date' => now()->addMonths(4)->toDateString(),
            'status' => 'active',
            'is_current' => false, // the real demo cycle stays "current"; this one is opened explicitly by code below.
            'activated_at' => now(),
            'created_by' => $programManager->id,
        ]);

        // 17 requirements, one per screenshot scenario — see
        // docs/user-guides/qiyas/analysis-matrix.md for which is which.
        $labels = [
            '001' => 'المتطلب التجريبي رقم 1 — بلا بدء عمل',
            '002' => 'المتطلب التجريبي رقم 2 — لعرض رفع الأدلة والإرسال',
            '003' => 'المتطلب التجريبي رقم 3 — لعرض التصحيح وإعادة الإرسال',
            '004' => 'المتطلب التجريبي رقم 4 — مسودة',
            '005' => 'المتطلب التجريبي رقم 5 — لعرض موافقة مدير الإدارة',
            '006' => 'المتطلب التجريبي رقم 6 — لعرض رفض مدير الإدارة',
            '007' => 'المتطلب التجريبي رقم 7 — بانتظار مدير الإدارة',
            '008' => 'المتطلب التجريبي رقم 8 — لعرض موافقة المدقق',
            '009' => 'المتطلب التجريبي رقم 9 — لعرض رفض المدقق',
            '010' => 'المتطلب التجريبي رقم 10 — بانتظار المدقق',
            '011' => 'المتطلب التجريبي رقم 11 — لعرض الاعتماد النهائي',
            '012' => 'المتطلب التجريبي رقم 12 — لعرض الرفض النهائي',
            '013' => 'المتطلب التجريبي رقم 13 — بانتظار الاعتماد النهائي',
            '014' => 'المتطلب التجريبي رقم 14 — معتمد',
            '015' => 'المتطلب التجريبي رقم 15 — متأخر',
            '016' => 'المتطلب التجريبي رقم 16 — لعرض طلب التمديد',
            '017' => 'المتطلب التجريبي رقم 17 — طلب تمديد قائم',
        ];

        $standards = [];
        foreach ($labels as $suffix => $nameAr) {
            $standards[$suffix] = Standard::create([
                'cycle_id' => $cycle->id,
                'compliance_program_id' => $program->id,
                'standard_number' => "QIYAS-TEST-{$suffix}",
                'name_ar' => $nameAr,
                'name_en' => "Test Requirement {$suffix}",
                'description' => 'وصف تجريبي لأغراض توثيق دليل المستخدم.',
                'version' => '1.0',
                'weight' => 1,
                'perspective' => 'المنظور التجريبي',
                'axis' => 'المحور التجريبي',
                'application_requirements' => 'متطلبات تطبيق تجريبية لغرض التوثيق.',
                'evidence_documents' => 'دليل إثبات تجريبي',
                'is_active' => true,
                'status' => 'active',
            ]);
        }

        $assign = fn (string $suffix, ?User $emp, ?string $due = null) => $workflow->assign(
            $standards[$suffix], $program, $programManager, $department, $emp,
            $due ?? now()->addDays(14)->toDateString(), 'medium', 'تعليمات تجريبية للإسناد.', null,
        );

        $upload = fn ($submission, User $emp) => $workflow->addFile(
            $submission,
            UploadedFile::fake()->create('دليل-إثبات-تجريبي.pdf', 100, 'application/pdf'),
            $emp,
        );

        // 001 — assigned only.
        $assign('001', $employee);

        // 002 — assigned only, reserved for a live upload/submit walkthrough.
        $assign('002', $employee);

        // 003 — pre-rejected, reserved for a live "correct and resubmit" walkthrough.
        $a = $assign('003', $employee);
        $sub = $workflow->getOrCreateDraft($a, $employee);
        $upload($sub, $employee);
        $sub = $workflow->submit($sub, $employee, null);
        $workflow->reject($sub, $departmentManager, 'department_manager', 'department-manager', 'مستند الإثبات غير مكتمل — يرجى إرفاق الصفحة الموقعة.', null);

        // 004 — draft (uploaded, not submitted).
        $a = $assign('004', $employee);
        $sub = $workflow->getOrCreateDraft($a, $employee);
        $upload($sub, $employee);

        // 005/006/007 — pending_department_manager (approve / reject / stays pending).
        foreach (['005', '006', '007'] as $suffix) {
            $a = $assign($suffix, $employee);
            $sub = $workflow->getOrCreateDraft($a, $employee);
            $upload($sub, $employee);
            $workflow->submit($sub, $employee, 'الأدلة جاهزة للمراجعة.');
        }

        // 008/009/010 — pending_auditor (approve / reject / stays pending).
        foreach (['008', '009', '010'] as $suffix) {
            $a = $assign($suffix, $employee);
            $sub = $workflow->getOrCreateDraft($a, $employee);
            $upload($sub, $employee);
            $sub = $workflow->submit($sub, $employee, null);
            $workflow->approve($sub, $departmentManager, 'department_manager', 'department-manager', 'تمت المراجعة والموافقة.');
        }

        // 011/012/013 — pending_program_manager (approve / reject / stays pending).
        foreach (['011', '012', '013'] as $suffix) {
            $a = $assign($suffix, $employee);
            $sub = $workflow->getOrCreateDraft($a, $employee);
            $upload($sub, $employee);
            $sub = $workflow->submit($sub, $employee, null);
            $sub = $workflow->approve($sub, $departmentManager, 'department_manager', 'department-manager', null);
            $workflow->approve($sub, $auditor, 'auditor', 'auditor', 'تم التحقق من الأدلة.');
        }

        // 014 — approved (full chain).
        $a = $assign('014', $employee);
        $sub = $workflow->getOrCreateDraft($a, $employee);
        $upload($sub, $employee);
        $sub = $workflow->submit($sub, $employee, null);
        $sub = $workflow->approve($sub, $departmentManager, 'department_manager', 'department-manager', null);
        $sub = $workflow->approve($sub, $auditor, 'auditor', 'auditor', null);
        $workflow->approve($sub, $programManager, 'program_manager', 'program-manager', 'تم الاعتماد النهائي.');

        // 015 — overdue (assigned, due date in the past, still not submitted).
        $a = $assign('015', $employee, now()->subDays(5)->toDateString());
        $workflow->getOrCreateDraft($a, $employee);

        // 016 — reserved for a live "request extension" walkthrough.
        $assign('016', $employee);

        // 017 — a pending extension request, reserved for a live auditor decision walkthrough.
        $a17 = $assign('017', $employee);
        $extensions->request($a17, $employee, now()->addDays(21)->toDateString(), 'الوقت الحالي غير كافٍ لتجميع الأدلة المطلوبة من الجهات المعنية.');

        // Executive Viewer and Super Admin need no additional data — they
        // read the same records above through their own dashboards/reports.

        $this->command?->info('Qiyas documentation data seeded: 1 department, 5 accounts, 1 cycle, 17 requirements covering every status.');
    }
}
