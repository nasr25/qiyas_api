<?php

namespace App\Console\Commands;

use Database\Seeders\DemoDataSeeder;
use Illuminate\Console\Command;

/**
 * Populates the full demo/testing dataset (departments, users for every role,
 * an active cycle, sample standards/requirements/documents, extensions,
 * notifications and audit logs).
 */
class DemoDataCommand extends Command
{
    protected $signature = 'system:demo-data';

    protected $description = 'Populate the platform with demo/testing data for all roles.';

    public function handle(): int
    {
        $this->info('Seeding demo data…');
        $this->callSilent('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true]);

        $this->info('Done. Test accounts (password: Password123!):');
        $this->line('  superadmin · auditor · coordinator · employee · executive');

        return self::SUCCESS;
    }
}
