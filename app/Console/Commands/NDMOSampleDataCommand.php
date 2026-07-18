<?php

namespace App\Console\Commands;

use Database\Seeders\NDMOSampleDataSeeder;
use Illuminate\Console\Command;

/**
 * Populates NDMO with clearly-marked development/testing sample content
 * (a content version, an active test cycle, a five-level test hierarchy,
 * and a sample Data Owner/Data Steward responsibility assignment). Never
 * runs as part of `migrate --seed` — see NDMOSampleDataSeeder's class
 * comment.
 */
class NDMOSampleDataCommand extends Command
{
    protected $signature = 'system:ndmo-sample-data';

    protected $description = 'Populate NDMO with development/testing-only sample hierarchy data (not official content).';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to seed development-only sample content in a production environment.');

            return self::FAILURE;
        }

        $this->info('Seeding NDMO test hierarchy…');
        $this->callSilent('db:seed', ['--class' => NDMOSampleDataSeeder::class, '--force' => true]);
        $this->info('Done. NDMO test accounts (password: Password123!): ndmo_pm · ndmo_auditor · ndmo_dept_a_manager · ndmo_employee_a · ndmo_dept_b_manager · ndmo_employee_b · ndmo_data_owner_a · ndmo_data_steward_a');

        return self::SUCCESS;
    }
}
