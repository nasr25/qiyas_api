<?php

namespace App\Console\Commands;

use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clears disposable compliance CONTENT so the dynamic hierarchy engine can
 * be rebuilt cleanly, while leaving every piece of shared platform data
 * untouched.
 *
 * This exists as an explicit, auditable command rather than ad-hoc SQL
 * because the distinction it encodes is a safety property, not a
 * convenience: content is per-cycle test data and is reproducible from
 * seeders; platform data (identity, authorization, configuration) is not.
 *
 * Development and testing environments only — refuses to run in production
 * without --force, and always prints what it will delete first.
 */
class ResetHierarchyContent extends Command
{
    protected $signature = 'compliance:reset-hierarchy-content
                            {--dry-run : Report what would be deleted and exit}
                            {--force : Required to run outside local/testing}';

    protected $description = 'Clear disposable compliance content (hierarchy, requirements, evidence, workflow) preserving all platform data.';

    /**
     * Deleted, child-first so foreign keys stay satisfied without ever
     * disabling constraint checks.
     */
    private const CONTENT_TABLES = [
        'evidence_files',
        'workflow_decisions',
        'workflow_events',
        'sla_instances',
        'extension_requests',
        'comment_attachments',
        'comments',
        'evidence_submissions',
        'requirement_assignments',
        'compliance_responsibilities',
        'document_versions',
        'documents',
        'evidence_requirements',
        'department_standard',
        'notifications',
        'notification_logs',
        'import_logs',
        'compliance_nodes',
        'standards',
        'compliance_content_versions',
        'assessment_cycles',
    ];

    /**
     * Never touched. Listed explicitly so the guarantee is reviewable in
     * code rather than implied by omission.
     */
    private const PRESERVED_TABLES = [
        'users', 'departments', 'roles', 'permissions',
        'model_has_roles', 'model_has_permissions', 'role_has_permissions',
        'program_user_roles', 'compliance_programs',
        'program_configurations', 'program_configuration_versions',
        'settings', 'setting_versions', 'branding_assets',
        'smtp_settings', 'email_templates', 'email_logs',
        'audit_logs',
        'workflow_definitions', 'workflow_stage_definitions', 'workflow_transition_definitions',
        'sla_settings',
        'hierarchy_definitions', 'hierarchy_level_definitions', 'program_structure_versions',
    ];

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing']) && ! $this->option('force')) {
            $this->error('Refusing to run outside local/testing without --force.');

            return self::FAILURE;
        }

        $this->info('Compliance content reset');
        $this->newLine();

        $rows = [];
        $total = 0;
        foreach (self::CONTENT_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $count = DB::table($table)->count();
            $total += $count;
            $rows[] = [$table, $count];
        }

        $this->comment('Will DELETE (disposable compliance content):');
        $this->table(['Table', 'Rows'], $rows);

        $preserved = [];
        foreach (self::PRESERVED_TABLES as $table) {
            if (Schema::hasTable($table)) {
                $preserved[] = [$table, DB::table($table)->count()];
            }
        }
        $this->comment('Will PRESERVE (shared platform data):');
        $this->table(['Table', 'Rows'], $preserved);

        if ($this->option('dry-run')) {
            $this->line("Dry run — nothing deleted. {$total} content row(s) would be removed.");

            return self::SUCCESS;
        }

        $deleted = 0;
        DB::transaction(function () use (&$deleted) {
            foreach (self::CONTENT_TABLES as $table) {
                if (Schema::hasTable($table)) {
                    $deleted += DB::table($table)->delete();
                }
            }
        });

        $this->newLine();
        $this->info("Deleted {$deleted} compliance content row(s). Platform data untouched.");

        // Deliberately logged AFTER the delete, into the preserved audit table.
        AuditService::log(
            'compliance.content_reset',
            "Compliance content reset: {$deleted} row(s) deleted across ".count(self::CONTENT_TABLES).' content tables.',
        );

        return self::SUCCESS;
    }
}
