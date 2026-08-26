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
            // WorkflowService, which reads stage transitions from these
            // seeded rows rather than a PHP constant.
            QiyasWorkflowDefinitionSeeder::class,
            QiyasProgramConfigurationSeeder::class,
            // Phase 5: Sumoud's own workflow/configuration data (entirely
            // separate rows from Qiyas's — see docs/cross-program-isolation.md)
            // plus its test accounts. Content itself comes from the
            // program-agnostic HierarchyContentSeeder below.
            SumoudWorkflowDefinitionSeeder::class,
            SumoudProgramConfigurationSeeder::class,
            SumoudTestAccountsSeeder::class,
            // Phase 6: ECC's own workflow/configuration data (entirely
            // separate rows from Qiyas's/Sumoud's) plus its test accounts.
            ECCWorkflowDefinitionSeeder::class,
            ECCProgramConfigurationSeeder::class,
            ECCTestAccountsSeeder::class,
            // Phase 7: NDMO's own workflow/configuration data (entirely
            // separate rows from Qiyas's/Sumoud's/ECC's) plus its test
            // accounts.
            NDMOWorkflowDefinitionSeeder::class,
            NDMOProgramConfigurationSeeder::class,
            NDMOTestAccountsSeeder::class,

            // ── Dynamic hierarchy engine ────────────────────────────────
            // Each program's own structure (3 / 5 / 5 / 6 levels), then the
            // content inside it, then live workflow data. All three are
            // program-agnostic: they read whatever structure a program
            // defines rather than encoding any program's shape in PHP,
            // which is what replaced the four hand-written sample seeders.
            // See docs/dynamic-compliance-structure.md.
            HierarchyStructureSeeder::class,
            HierarchyContentSeeder::class,
            HierarchyWorkflowSeeder::class,
        ]);
    }
}
