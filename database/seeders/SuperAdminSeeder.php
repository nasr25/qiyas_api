<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Creates the default Super Admin account.
 *
 * The password is NOT hardcoded. It comes from SUPERADMIN_INITIAL_PASSWORD
 * when set, otherwise a random one is generated and printed once. Either
 * way the account is created with `must_change_password = true`, which
 * JwtMiddleware now enforces on every API request — the previous version
 * shipped a documented literal ("ChangeMe123!") and set that flag to false,
 * so a production install could sit indefinitely on a publicly known
 * super-admin credential.
 *
 * Existing installations are not re-passworded: firstOrCreate only applies
 * these values when the account does not yet exist.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $existing = User::where('username', 'superadmin')->first();
        if ($existing) {
            $this->command?->info('Super Admin already exists — left untouched.');

            return;
        }

        $password = (string) env('SUPERADMIN_INITIAL_PASSWORD', '');
        $generated = $password === '';
        if ($generated) {
            $password = Str::password(20);
        }

        $user = User::create([
            'username' => 'superadmin',
            'name' => 'Super Administrator',
            'email' => env('SUPERADMIN_EMAIL', 'admin@localhost.local'),
            'password' => $password,
            'auth_type' => 'local',
            'is_active' => true,
            'must_change_password' => true,
            'locale' => 'ar',
        ]);

        $user->syncRoles(['super-admin']);

        $this->command?->info('Super Admin created: superadmin (password change required at first sign-in).');
        if ($generated) {
            $this->command?->warn('Generated initial password (shown once, store it securely): '.$password);
        }
    }
}
