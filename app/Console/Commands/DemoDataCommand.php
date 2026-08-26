<?php

namespace App\Console\Commands;

use Database\Seeders\DemoDataSeeder;
use Illuminate\Console\Command;

/**
 * Populates the full demo/testing dataset (departments, users for every
 * role, an active cycle, sample compliance content, extensions,
 * notifications and audit logs).
 *
 * Development and testing only. The accounts it creates share one publicly
 * known password, so this refuses to run in production — previously it did
 * not, and printed those credentials to stdout on success.
 */
class DemoDataCommand extends Command
{
    protected $signature = 'system:demo-data';

    protected $description = 'Populate the platform with demo/testing data for all roles (non-production only).';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run: this creates accounts with a shared, publicly known password.');
            $this->line('Production databases must be provisioned with real accounts instead.');

            return self::FAILURE;
        }

        $this->info('Seeding demo data…');
        $this->callSilent('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true]);

        $this->info('Done. Test accounts (password: Password123!):');
        $this->line('  superadmin · auditor · coordinator · employee · executive');

        return self::SUCCESS;
    }
}
