<?php

namespace App\Console\Commands;

use App\Models\ComplianceProgram;
use App\Models\User;
use App\Services\ProgramMigrationService;
use Illuminate\Console\Command;

/**
 * Idempotent data migration: maps existing users (via their global spatie
 * roles) onto the new program-scoped authorization layer for QIYAS.
 *
 * Schema-level backfill (compliance_program_id on cycles/standards/documents/
 * extension_requests/comments/audit_logs) already happened inside the
 * migrations themselves, since that data is purely FK-derivable and does not
 * depend on seeded users. This command exists separately because the
 * user -> program_user_roles mapping depends on users/roles already
 * existing, which is only true after seeding — it cannot safely run inside
 * `php artisan migrate` on a fresh install.
 *
 * Safe to re-run any number of times: existing rows are left untouched.
 */
class MigrateQiyasToProgram extends Command
{
    protected $signature = 'compliance:migrate-qiyas {--dry-run : Report what would change without writing anything}';

    protected $description = 'Backfill program_user_roles for existing users based on their current spatie roles (QIYAS program).';

    public function handle(ProgramMigrationService $service): int
    {
        $program = ComplianceProgram::where('code', 'QIYAS')->first();
        if (! $program) {
            $this->error('QIYAS compliance program not found. Run migrations first.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run — no changes will be written.');
            $count = User::with('roles')->get()->sum(fn ($u) => $u->roles->count());
            $this->line("Would evaluate role assignments for {$count} user-role pairs.");

            return self::SUCCESS;
        }

        $admin = User::whereHas('roles', fn ($q) => $q->where('name', 'super-admin'))->first();

        $this->info("Migrating user program roles for '{$program->code}'...");
        $result = $service->migrateUsersToProgram($program, $admin);

        $this->table(
            ['Program user roles created', 'Already existed (skipped)'],
            [[$result['created'], $result['already_existed']]],
        );

        $this->info('Done. Run `php artisan compliance:verify-migration` to verify data integrity.');

        return self::SUCCESS;
    }
}
