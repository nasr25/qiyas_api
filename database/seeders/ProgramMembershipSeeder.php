<?php

namespace Database\Seeders;

use App\Models\ComplianceProgram;
use App\Models\User;
use App\Services\ProgramMigrationService;
use Illuminate\Database\Seeder;

/**
 * Runs the QIYAS role migration (spatie roles -> program_user_roles) as part
 * of the standard seed flow, so a fresh `migrate --seed` install ends up
 * fully wired for the Program Selection page. Equivalent to running
 * `php artisan compliance:migrate-qiyas` by hand. Idempotent.
 */
class ProgramMembershipSeeder extends Seeder
{
    public function run(ProgramMigrationService $service): void
    {
        $program = ComplianceProgram::where('code', 'QIYAS')->first();
        if (! $program) {
            return;
        }

        $admin = User::whereHas('roles', fn ($q) => $q->where('name', 'super-admin'))->first();

        $service->migrateUsersToProgram($program, $admin);
    }
}
