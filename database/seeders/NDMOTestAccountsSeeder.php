<?php

namespace Database\Seeders;

use App\Models\ComplianceProgram;
use App\Models\Department;
use App\Models\ProgramUserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Test/demo accounts for exercising NDMO as a fourth, isolated program —
 * NOT official NDMO content, only accounts and role memberships. Mirrors
 * ECCTestAccountsSeeder's pattern exactly: every program_user_roles row
 * is created explicitly, one at a time, so no existing Qiyas/Sumoud/ECC
 * user is ever automatically granted NDMO access as a side effect.
 *
 * Includes the Phase 7 brief's explicit quad-program role scenario: one
 * user with Qiyas Program Manager, Sumoud Auditor, ECC Employee, and
 * NDMO Department Manager.
 */
class NDMOTestAccountsSeeder extends Seeder
{
    private const PASSWORD = 'Password123!';

    public static function usernames(): array
    {
        return [
            'ndmo_pm', 'ndmo_auditor', 'ndmo_dept_a_manager', 'ndmo_employee_a',
            'ndmo_dept_b_manager', 'ndmo_employee_b', 'ndmo_data_owner_a', 'ndmo_data_steward_a',
            'quadprogram_qiyas_pm_sumoud_auditor_ecc_emp_ndmo_deptmgr',
        ];
    }

    public function run(): void
    {
        $ndmo = ComplianceProgram::where('code', 'NDMO')->first();
        $qiyas = ComplianceProgram::where('code', 'QIYAS')->first();
        $sumoud = ComplianceProgram::where('code', 'SUMOUD')->first();
        $ecc = ComplianceProgram::where('code', 'ECC')->first();
        if (! $ndmo) {
            return;
        }

        $it = Department::where('name_en', 'Information Technology')->first();
        $hr = Department::where('name_en', 'Human Resources')->first();
        $superAdmin = User::where('username', 'superadmin')->first();

        // ── NDMO-only accounts ──────────────────────────────────────────
        $this->account('ndmo_pm', 'NDMO Program Manager', null);
        $this->grant($ndmo, 'ndmo_pm', ProgramUserRole::ROLE_PROGRAM_MANAGER, null, $superAdmin);

        $this->account('ndmo_auditor', 'NDMO Auditor', null);
        $this->grant($ndmo, 'ndmo_auditor', ProgramUserRole::ROLE_AUDITOR, null, $superAdmin);

        $this->account('ndmo_dept_a_manager', 'NDMO Department Manager A', $it?->id);
        $this->grant($ndmo, 'ndmo_dept_a_manager', ProgramUserRole::ROLE_DEPARTMENT_MANAGER, $it?->id, $superAdmin);

        $this->account('ndmo_employee_a', 'NDMO Employee A', $it?->id);
        $this->grant($ndmo, 'ndmo_employee_a', ProgramUserRole::ROLE_EMPLOYEE, $it?->id, $superAdmin);

        $this->account('ndmo_dept_b_manager', 'NDMO Department Manager B', $hr?->id);
        $this->grant($ndmo, 'ndmo_dept_b_manager', ProgramUserRole::ROLE_DEPARTMENT_MANAGER, $hr?->id, $superAdmin);

        $this->account('ndmo_employee_b', 'NDMO Employee B', $hr?->id);
        $this->grant($ndmo, 'ndmo_employee_b', ProgramUserRole::ROLE_EMPLOYEE, $hr?->id, $superAdmin);

        // Data Owner / Data Steward test people — plain NDMO employees who
        // may additionally be assigned a responsibility label on a given
        // assignment; the label itself grants no extra workflow access.
        $this->account('ndmo_data_owner_a', 'NDMO Data Owner A', $it?->id);
        $this->grant($ndmo, 'ndmo_data_owner_a', ProgramUserRole::ROLE_EMPLOYEE, $it?->id, $superAdmin);

        $this->account('ndmo_data_steward_a', 'NDMO Data Steward A', $it?->id);
        $this->grant($ndmo, 'ndmo_data_steward_a', ProgramUserRole::ROLE_EMPLOYEE, $it?->id, $superAdmin);

        if (! $qiyas || ! $sumoud || ! $ecc) {
            return;
        }

        // Quad-program role scenario: Qiyas Program Manager, Sumoud
        // Auditor, ECC Employee, NDMO Department Manager.
        $username = 'quadprogram_qiyas_pm_sumoud_auditor_ecc_emp_ndmo_deptmgr';
        $this->account($username, 'Quad-Program User', $it?->id);
        $this->grant($qiyas, $username, ProgramUserRole::ROLE_PROGRAM_MANAGER, null, $superAdmin);
        $this->grant($sumoud, $username, ProgramUserRole::ROLE_AUDITOR, null, $superAdmin);
        $this->grant($ecc, $username, ProgramUserRole::ROLE_EMPLOYEE, $it?->id, $superAdmin);
        $this->grant($ndmo, $username, ProgramUserRole::ROLE_DEPARTMENT_MANAGER, $it?->id, $superAdmin);
    }

    private function account(string $username, string $name, ?int $departmentId): void
    {
        User::updateOrCreate(
            ['username' => $username],
            [
                'name' => $name,
                'email' => $username.'@ndmo.local',
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
