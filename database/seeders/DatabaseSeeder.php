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
            // Phase 2: default bilingual notification templates.
            EmailTemplatesSeeder::class,
            // Phase 4: Qiyas's workflow and program-configuration values,
            // represented as engine data instead of hardcoded PHP — see
            // docs/qiyas-engine-configuration.md. Must run BEFORE
            // QiyasWorkflowDemoSeeder, which drives real submissions through
            // WorkflowService — and as of Phase 4, WorkflowService reads
            // stage transitions from these seeded rows, not a PHP constant.
            QiyasWorkflowDefinitionSeeder::class,
            QiyasProgramConfigurationSeeder::class,
            // Phase 2: department-manager/employee test accounts for
            // Department B, and workflow demo data covering every status.
            QiyasWorkflowDemoSeeder::class,
            // Phase 5: Sumoud's own workflow/configuration data (entirely
            // separate rows from Qiyas's — see docs/cross-program-isolation.md)
            // plus its test accounts. No official Sumoud content is seeded
            // here — see SumoudSampleDataSeeder, which is intentionally NOT
            // called from this production seeding path.
            SumoudWorkflowDefinitionSeeder::class,
            SumoudProgramConfigurationSeeder::class,
            SumoudTestAccountsSeeder::class,
            // Phase 6: ECC's own workflow/configuration data (entirely
            // separate rows from Qiyas's/Sumoud's) plus its test accounts.
            // No official ECC content is seeded here — see
            // ECCSampleDataSeeder, which is intentionally NOT called from
            // this production seeding path.
            ECCWorkflowDefinitionSeeder::class,
            ECCProgramConfigurationSeeder::class,
            ECCTestAccountsSeeder::class,
        ]);
    }
}
