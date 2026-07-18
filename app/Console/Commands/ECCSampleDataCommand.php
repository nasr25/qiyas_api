<?php

namespace App\Console\Commands;

use Database\Seeders\ECCSampleDataSeeder;
use Illuminate\Console\Command;

/**
 * Populates ECC with clearly-marked development/testing sample content
 * (a content version, an active test cycle, and a small four-level test
 * hierarchy). Never runs as part of `migrate --seed` — see
 * ECCSampleDataSeeder's class comment.
 */
class ECCSampleDataCommand extends Command
{
    protected $signature = 'system:ecc-sample-data';

    protected $description = 'Populate ECC with development/testing-only sample hierarchy data (not official content).';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to seed development-only sample content in a production environment.');

            return self::FAILURE;
        }

        $this->info('Seeding ECC test hierarchy…');
        $this->callSilent('db:seed', ['--class' => ECCSampleDataSeeder::class, '--force' => true]);
        $this->info('Done. ECC test accounts (password: Password123!): ecc_pm · ecc_auditor · ecc_dept_a_manager · ecc_employee_a · ecc_dept_b_manager · ecc_employee_b');

        return self::SUCCESS;
    }
}
