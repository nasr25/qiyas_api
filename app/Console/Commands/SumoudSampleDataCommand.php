<?php

namespace App\Console\Commands;

use Database\Seeders\SumoudSampleDataSeeder;
use Illuminate\Console\Command;

/**
 * Populates Sumoud with clearly-marked development/testing sample content
 * (an active test cycle plus a small test hierarchy). Never runs as part
 * of `migrate --seed` — see SumoudSampleDataSeeder's class comment.
 */
class SumoudSampleDataCommand extends Command
{
    protected $signature = 'system:sumoud-sample-data';

    protected $description = 'Populate Sumoud with development/testing-only sample hierarchy data (not official content).';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to seed development-only sample content in a production environment.');

            return self::FAILURE;
        }

        $this->info('Seeding Sumoud test hierarchy…');
        $this->callSilent('db:seed', ['--class' => SumoudSampleDataSeeder::class, '--force' => true]);
        $this->info('Done. Sumoud test accounts (password: Password123!): sumoud_pm · sumoud_auditor · sumoud_dept_a_manager · sumoud_employee_a · sumoud_dept_b_manager · sumoud_employee_b');

        return self::SUCCESS;
    }
}
