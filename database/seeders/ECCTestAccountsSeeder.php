<?php

namespace Database\Seeders;

use App\Models\ComplianceProgram;
use App\Models\Department;
use App\Models\ProgramUserRole;
use App\Models\User;
use Database\Seeders\Concerns\NonProductionSeeder;
use Illuminate\Database\Seeder;

/**
 * Test/demo accounts for exercising ECC as a third, isolated program — NOT
 * official ECC content, only accounts and role memberships. Mirrors
 * SumoudTestAccountsSeeder's pattern exactly: every program_user_roles row
 * is created explicitly, one at a time, so no existing Qiyas or Sumoud
 * user is ever automatically granted ECC access as a side effect.
 *
 * Includes the Phase 6 brief's explicit multi-role scenarios:
 *   - User A: Qiyas Program Manager, Sumoud Auditor, ECC Employee.
 *   - User B: Qiyas Employee, Sumoud Department Manager, ECC Program Manager.
 */
class ECCTestAccountsSeeder extends Seeder
{
    use NonProductionSeeder;

    private const PASSWORD = 'Password123!';

    public static function usernames(): array
    {
        return [
            'ecc_pm', 'ecc_auditor', 'ecc_dept_a_manager', 'ecc_employee_a',
            'ecc_dept_b_manager', 'ecc_employee_b',
            'triprogram_qiyas_pm_sumoud_auditor_ecc_employee',
            'triprogram_qiyas_emp_sumoud_deptmgr_ecc_pm',
        ];
    }

    public function run(): void
    {
        $this->guardAgainstProduction();

        $ecc = ComplianceProgram::where('code', 'ECC')->first();
        $qiyas = ComplianceProgram::where('code', 'QIYAS')->first();
        $sumoud = ComplianceProgram::where('code', 'SUMOUD')->first();
        if (! $ecc) {
            return;
        }

        $it = Department::where('name_en', 'Information Technology')->first();
        $hr = Department::where('name_en', 'Human Resources')->first();
        $superAdmin = User::where('username', 'superadmin')->first();

        // ── ECC-only accounts ───────────────────────────────────────────
        $this->account('ecc_pm', 'ECC Program Manager', null);
        $this->grant($ecc, 'ecc_pm', ProgramUserRole::ROLE_PROGRAM_MANAGER, null, $superAdmin);

        $this->account('ecc_auditor', 'ECC Auditor', null);
        $this->grant($ecc, 'ecc_auditor', ProgramUserRole::ROLE_AUDITOR, null, $superAdmin);

        $this->account('ecc_dept_a_manager', 'ECC Department Manager A', $it?->id);
        $this->grant($ecc, 'ecc_dept_a_manager', ProgramUserRole::ROLE_DEPARTMENT_MANAGER, $it?->id, $superAdmin);

        $this->account('ecc_employee_a', 'ECC Employee A', $it?->id);
        $this->grant($ecc, 'ecc_employee_a', ProgramUserRole::ROLE_EMPLOYEE, $it?->id, $superAdmin);

        $this->account('ecc_dept_b_manager', 'ECC Department Manager B', $hr?->id);
        $this->grant($ecc, 'ecc_dept_b_manager', ProgramUserRole::ROLE_DEPARTMENT_MANAGER, $hr?->id, $superAdmin);

        $this->account('ecc_employee_b', 'ECC Employee B', $hr?->id);
        $this->grant($ecc, 'ecc_employee_b', ProgramUserRole::ROLE_EMPLOYEE, $hr?->id, $superAdmin);

        if (! $qiyas || ! $sumoud) {
            return;
        }

        // User A: Qiyas Program Manager, Sumoud Auditor, ECC Employee.
        $this->account('triprogram_qiyas_pm_sumoud_auditor_ecc_employee', 'Tri-Program User A', $it?->id);
        $this->grant($qiyas, 'triprogram_qiyas_pm_sumoud_auditor_ecc_employee', ProgramUserRole::ROLE_PROGRAM_MANAGER, null, $superAdmin);
        $this->grant($sumoud, 'triprogram_qiyas_pm_sumoud_auditor_ecc_employee', ProgramUserRole::ROLE_AUDITOR, null, $superAdmin);
        $this->grant($ecc, 'triprogram_qiyas_pm_sumoud_auditor_ecc_employee', ProgramUserRole::ROLE_EMPLOYEE, $it?->id, $superAdmin);

        // User B: Qiyas Employee, Sumoud Department Manager, ECC Program Manager.
        $this->account('triprogram_qiyas_emp_sumoud_deptmgr_ecc_pm', 'Tri-Program User B', $it?->id);
        $this->grant($qiyas, 'triprogram_qiyas_emp_sumoud_deptmgr_ecc_pm', ProgramUserRole::ROLE_EMPLOYEE, $it?->id, $superAdmin);
        $this->grant($sumoud, 'triprogram_qiyas_emp_sumoud_deptmgr_ecc_pm', ProgramUserRole::ROLE_DEPARTMENT_MANAGER, $it?->id, $superAdmin);
        $this->grant($ecc, 'triprogram_qiyas_emp_sumoud_deptmgr_ecc_pm', ProgramUserRole::ROLE_PROGRAM_MANAGER, null, $superAdmin);
    }

    private function account(string $username, string $name, ?int $departmentId): void
    {
        User::updateOrCreate(
            ['username' => $username],
            [
                'name' => $name,
                'email' => $username.'@ecc.local',
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
