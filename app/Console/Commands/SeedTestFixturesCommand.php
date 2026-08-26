<?php

namespace App\Console\Commands;

use Database\Seeders\TestHierarchyFixtureSeeder;
use Illuminate\Console\Command;

/**
 * Builds the 3/5/7-level test programs used by the Playwright suites and
 * the depth-independence proofs. Development and testing only — refuses to
 * run elsewhere without --force, because it creates users with a known
 * password.
 */
class SeedTestFixturesCommand extends Command
{
    protected $signature = 'compliance:seed-test-fixtures {--force : Allow outside local/testing}';

    protected $description = 'Seed the 3-, 5- and 7-level test fixture programs (development/testing only).';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing']) && ! $this->option('force')) {
            $this->error('Refusing to run outside local/testing without --force.');

            return self::FAILURE;
        }

        $this->info('Seeding hierarchy test fixtures…');
        $this->callSilent('db:seed', ['--class' => TestHierarchyFixtureSeeder::class, '--force' => true]);

        // Array `+` keeps the left-hand key, and MUTABLE_FIXTURE shares
        // depth 5 with TEST5 — so it must be appended, not merged.
        $fixtures = TestHierarchyFixtureSeeder::FIXTURES;
        $fixtures[] = TestHierarchyFixtureSeeder::MUTABLE_FIXTURE;

        foreach ($fixtures as $depth => $code) {
            $depth = is_int($depth) && $depth > 2 ? $depth : 5;
            $prefix = strtolower($code);
            $this->line("  {$code} ({$depth} levels) — {$prefix}_pm · {$prefix}_auditor · {$prefix}_dept_manager · {$prefix}_employee · {$prefix}_employee_b");
        }
        $this->line('  password: '.TestHierarchyFixtureSeeder::PASSWORD);

        return self::SUCCESS;
    }
}
