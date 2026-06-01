<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Creates the default Super Admin account.
 * Username: superadmin | Password: ChangeMe123! (force change on first login)
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['username' => 'superadmin'],
            [
                'name'                 => 'Super Administrator',
                'email'                => 'admin@localhost.local',
                'password'             => 'ChangeMe123!',
                'auth_type'            => 'local',
                'is_active'            => true,
                'must_change_password' => false,
                'locale'               => 'ar',
            ]
        );

        $user->syncRoles(['super-admin']);

        $this->command->info("Super Admin created: superadmin / ChangeMe123! (must change password)");
    }
}
