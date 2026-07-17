<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            DefaultSettingsSeeder::class,
            // Departments + named test users + full demo dataset so the platform
            // is immediately usable and testable after `migrate --seed`.
            DemoDataSeeder::class,
            // The 89 real DGA Qiyas standards (into a separate draft cycle).
            StandardsCatalogSeeder::class,
            // Stamps created_by/updated_by on the QIYAS compliance program now
            // that a super-admin user exists.
            ComplianceProgramsAttributionSeeder::class,
            // Maps existing spatie roles onto the new program-scoped
            // authorization layer (program_user_roles). See
            // docs/qiyas-migration-plan.md.
            ProgramMembershipSeeder::class,
        ]);
    }
}
