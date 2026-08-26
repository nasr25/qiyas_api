<?php

namespace Database\Seeders;

use App\Models\ComplianceProgram;
use App\Models\Department;
use App\Models\ProgramUserRole;
use App\Models\User;
use Database\Seeders\Concerns\NonProductionSeeder;
use Illuminate\Database\Seeder;

/**
 * Test/demo accounts for exercising Sumoud as a second, isolated program —
 * NOT official Sumoud content, only accounts and role memberships needed to
 * prove the engine works for a second program. Deliberately NOT part of
 * ProgramMembershipSeeder's spatie-role migration (that seeder is a one-off
 * Qiyas legacy-data migration, not a generic mechanism) — every
 * program_user_roles row below is created explicitly, one at a time, so
 * that no existing Qiyas user is ever automatically granted Sumoud access
 * as a side effect (see Phase 5 brief, "Do not automatically grant existing
 * Qiyas users access to Sumoud").
 *
 * Reuses the SAME shared Department rows Qiyas already uses (Information
 * Technology / Human Resources) — departments are a shared, global entity,
 * never duplicated per program.
 *
 * Includes the four named cross-program role scenarios from the Phase 5
 * brief:
 *   - User A: Program Manager in Qiyas, Auditor in Sumoud.
 *   - User B: Employee in Qiyas, Department Manager in Sumoud.
 *   - User C: Auditor in Qiyas, no Sumoud access — satisfied by the
 *     existing `auditor_2` account, which this seeder never touches.
 *   - User D: Employee in both programs, same department, separate
 *     assignments/tasks (assignments themselves are created by whichever
 *     test/seed flow assigns requirements, not here).
 */
class SumoudTestAccountsSeeder extends Seeder
{
    use NonProductionSeeder;

    private const PASSWORD = 'Password123!';

    public static function usernames(): array
    {
        return [
            'sumoud_pm', 'sumoud_auditor', 'sumoud_dept_a_manager', 'sumoud_employee_a',
            'sumoud_dept_b_manager', 'sumoud_employee_b',
            'cross_pm_qiyas_auditor_sumoud', 'cross_employee_qiyas_deptmgr_sumoud', 'cross_employee_both_programs',
        ];
    }

    public function run(): void
    {
        $this->guardAgainstProduction();

        $sumoud = ComplianceProgram::where('code', 'SUMOUD')->first();
        $qiyas = ComplianceProgram::where('code', 'QIYAS')->first();
        if (! $sumoud) {
            return;
        }

        $it = Department::where('name_en', 'Information Technology')->first();
        $hr = Department::where('name_en', 'Human Resources')->first();
        $superAdmin = User::where('username', 'superadmin')->first();

        // ── Sumoud-only accounts ────────────────────────────────────────
        $this->account('sumoud_pm', 'Sumoud Program Manager', null);
        $this->grant($sumoud, 'sumoud_pm', ProgramUserRole::ROLE_PROGRAM_MANAGER, null, $superAdmin);

        $this->account('sumoud_auditor', 'Sumoud Auditor', null);
        $this->grant($sumoud, 'sumoud_auditor', ProgramUserRole::ROLE_AUDITOR, null, $superAdmin);

        $this->account('sumoud_dept_a_manager', 'Sumoud Department Manager A', $it?->id);
        $this->grant($sumoud, 'sumoud_dept_a_manager', ProgramUserRole::ROLE_DEPARTMENT_MANAGER, $it?->id, $superAdmin);

        $this->account('sumoud_employee_a', 'Sumoud Employee A', $it?->id);
        $this->grant($sumoud, 'sumoud_employee_a', ProgramUserRole::ROLE_EMPLOYEE, $it?->id, $superAdmin);

        $this->account('sumoud_dept_b_manager', 'Sumoud Department Manager B', $hr?->id);
        $this->grant($sumoud, 'sumoud_dept_b_manager', ProgramUserRole::ROLE_DEPARTMENT_MANAGER, $hr?->id, $superAdmin);

        $this->account('sumoud_employee_b', 'Sumoud Employee B', $hr?->id);
        $this->grant($sumoud, 'sumoud_employee_b', ProgramUserRole::ROLE_EMPLOYEE, $hr?->id, $superAdmin);

        if (! $qiyas) {
            return;
        }

        // ── Cross-program role scenarios ────────────────────────────────

        // User A: Program Manager in Qiyas, Auditor in Sumoud.
        $this->account('cross_pm_qiyas_auditor_sumoud', 'Cross-Program User A', null);
        $this->grant($qiyas, 'cross_pm_qiyas_auditor_sumoud', ProgramUserRole::ROLE_PROGRAM_MANAGER, null, $superAdmin);
        $this->grant($sumoud, 'cross_pm_qiyas_auditor_sumoud', ProgramUserRole::ROLE_AUDITOR, null, $superAdmin);

        // User B: Employee in Qiyas, Department Manager in Sumoud.
        $this->account('cross_employee_qiyas_deptmgr_sumoud', 'Cross-Program User B', $it?->id);
        $this->grant($qiyas, 'cross_employee_qiyas_deptmgr_sumoud', ProgramUserRole::ROLE_EMPLOYEE, $it?->id, $superAdmin);
        $this->grant($sumoud, 'cross_employee_qiyas_deptmgr_sumoud', ProgramUserRole::ROLE_DEPARTMENT_MANAGER, $it?->id, $superAdmin);

        // User D: Employee in both programs, same department.
        $this->account('cross_employee_both_programs', 'Cross-Program User D', $it?->id);
        $this->grant($qiyas, 'cross_employee_both_programs', ProgramUserRole::ROLE_EMPLOYEE, $it?->id, $superAdmin);
        $this->grant($sumoud, 'cross_employee_both_programs', ProgramUserRole::ROLE_EMPLOYEE, $it?->id, $superAdmin);

        // User C (Auditor in Qiyas, no Sumoud access) needs no new row —
        // the existing `auditor_2` account already has only a QIYAS
        // program_user_roles entry (from ProgramMembershipSeeder) and is
        // never touched here.
    }

    private function account(string $username, string $name, ?int $departmentId): void
    {
        User::updateOrCreate(
            ['username' => $username],
            [
                'name' => $name,
                'email' => $username.'@sumoud.local',
                'password' => self::PASSWORD,
                'auth_type' => 'local',
                'department_id' => $departmentId,
                'is_active' => true,
                'must_change_password' => false,
                'locale' => 'ar',
            ],
        );
    }

    private function grant(?ComplianceProgram $program, string $username, string $roleKey, ?int $departmentId, ?User $assignedBy): void
    {
        if (! $program) {
            return;
        }
        $user = User::where('username', $username)->first();
        if (! $user) {
            return;
        }

        ProgramUserRole::updateOrCreate(
            ['user_id' => $user->id, 'compliance_program_id' => $program->id, 'role_key' => $roleKey],
            ['department_id' => $departmentId, 'is_active' => true, 'assigned_by' => $assignedBy?->id, 'assigned_at' => now(), 'revoked_at' => null],
        );
    }
}
