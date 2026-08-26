<?php

namespace Database\Seeders\Concerns;

use RuntimeException;

/**
 * Guards seeders that create demo/test data — named accounts with shared,
 * publicly known passwords, sample compliance content, performance fixtures.
 *
 * These must never populate a production database. Nothing prevented that
 * before: `php artisan db:seed --force` under APP_ENV=production ran the
 * whole DatabaseSeeder list, test accounts included, which is exactly the
 * kind of step a deployment runbook invites.
 *
 * The escape hatch is deliberately explicit and awkward — a dedicated
 * environment variable, not a flag someone reaches for by habit — so that
 * seeding a production-like environment for a sanctioned test remains
 * possible but never accidental.
 */
trait NonProductionSeeder
{
    protected function guardAgainstProduction(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        if (filter_var(env('ALLOW_TEST_SEEDERS_IN_PRODUCTION', false), FILTER_VALIDATE_BOOL)) {
            $this->command?->warn(static::class.': running in production because ALLOW_TEST_SEEDERS_IN_PRODUCTION is set.');

            return;
        }

        throw new RuntimeException(
            static::class.' creates demo/test data and is blocked in production. '
            .'Set ALLOW_TEST_SEEDERS_IN_PRODUCTION=true only for a deliberately seeded test environment.'
        );
    }
}
