<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds all named test accounts (password "Password123!", no forced change).
 * Covers every role plus 2 employees per department. Idempotent.
 */
class TestUsersSeeder extends Seeder
{
    public const PASSWORD = 'Password123!';

    /** [username, name, role, department name_en (null = no department)] */
    public static function accounts(): array
    {
        $accounts = [
            ['superadmin',       'Super Admin',          'super-admin', null],
            ['qiyas_admin',      'Qiyas Administrator',  'qiyas-admin', null],
            ['auditor_1',        'Auditor One',          'auditor',     null],
            ['auditor_2',        'Auditor Two',          'auditor',     null],
            ['executive_viewer', 'Executive Viewer',     'executive',   null],
        ];

        // 2 employees per department.
        $depts = [
            'Information Technology' => 'it',
            'Human Resources'        => 'hr',
            'Finance'                => 'finance',
            'Legal'                  => 'legal',
            'Operations'             => 'operations',
        ];
        foreach ($depts as $deptEn => $prefix) {
            for ($i = 1; $i <= 2; $i++) {
                $accounts[] = [
                    "{$prefix}_employee_{$i}",
                    ucfirst($prefix) . " Employee {$i}",
                    'employee',
                    $deptEn,
                ];
            }
        }

        return $accounts;
    }

    public static function usernames(): array
    {
        return array_map(fn ($a) => $a[0], self::accounts());
    }

    public function run(): void
    {
        $deptIds = Department::pluck('id', 'name_en');

        foreach (self::accounts() as [$username, $name, $role, $deptEn]) {
            $user = User::updateOrCreate(
                ['username' => $username],
                [
                    'name'                 => $name,
                    'email'                => $username . '@qiyas.local',
                    'password'             => self::PASSWORD,
                    'auth_type'            => 'local',
                    'department_id'        => $deptEn ? ($deptIds[$deptEn] ?? null) : null,
                    'is_active'            => true,
                    'must_change_password' => false,
                    'locale'               => 'ar',
                ],
            );
            $user->syncRoles([$role]);
        }
    }
}
