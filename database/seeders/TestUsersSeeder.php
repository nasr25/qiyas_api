<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds the 5 named test accounts (one per role), all with password
 * "Password123!" and no forced password change. Idempotent.
 *
 * superadmin / auditor / coordinator / employee / executive
 */
class TestUsersSeeder extends Seeder
{
    public const PASSWORD = 'Password123!';

    public function run(): void
    {
        $dept = Department::query()->orderBy('id')->first();

        $accounts = [
            ['username' => 'superadmin',  'name' => 'Super Admin',       'role' => 'super-admin', 'department' => false],
            ['username' => 'auditor',     'name' => 'Auditor User',      'role' => 'auditor',     'department' => false],
            ['username' => 'coordinator', 'name' => 'Coordinator User',  'role' => 'coordinator', 'department' => true],
            ['username' => 'employee',    'name' => 'Employee User',     'role' => 'employee',    'department' => true],
            ['username' => 'executive',   'name' => 'Executive Viewer',  'role' => 'executive',   'department' => false],
        ];

        foreach ($accounts as $a) {
            $user = User::updateOrCreate(
                ['username' => $a['username']],
                [
                    'name'                 => $a['name'],
                    'email'                => $a['username'] . '@qiyas.local',
                    'password'             => self::PASSWORD,   // hashed via model cast
                    'auth_type'            => 'local',
                    'department_id'        => $a['department'] ? $dept?->id : null,
                    'is_active'            => true,
                    'must_change_password' => false,
                    'locale'               => 'ar',
                ],
            );
            $user->syncRoles([$a['role']]);
        }
    }
}
