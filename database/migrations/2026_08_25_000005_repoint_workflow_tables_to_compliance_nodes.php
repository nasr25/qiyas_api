<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror removal, step 1 of 2 (schema).
 *
 * `requirement_assignments` and `evidence_submissions` previously pointed at
 * `standards` — the two-level projection that discarded every ancestor above
 * index 1 (audit finding C2). They now point at `compliance_nodes`, the
 * arbitrary-depth tree, so an assignment on an NDMO subrequirement knows its
 * full six-level path instead of a truncated domain/policy pair.
 *
 * `requirement_id` is made nullable rather than dropped in this migration:
 * dropping it in the same step as adding the replacement would leave no way
 * to inspect pre-existing rows if the cutover needed review. It is dropped
 * in a follow-up once `compliance:verify-hierarchy` reports zero rows still
 * relying on it. Existing compliance content is disposable in this phase
 * (confirmed by the platform owner), so no backfill is attempted — see
 * docs/dynamic-hierarchy-migration-plan.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requirement_assignments', function (Blueprint $table) {
            $table->foreignId('compliance_node_id')->nullable()->after('program_cycle_id')
                ->constrained('compliance_nodes')->cascadeOnDelete();
            $table->index(['compliance_node_id', 'status'], 'requirement_assignments_node_status_idx');
        });

        Schema::table('evidence_submissions', function (Blueprint $table) {
            $table->foreignId('compliance_node_id')->nullable()->after('program_cycle_id')
                ->constrained('compliance_nodes')->restrictOnDelete();
            $table->index(['compliance_node_id', 'status'], 'evidence_submissions_node_status_idx');
        });

        // The legacy links become optional; the node link is authoritative.
        DB::statement('ALTER TABLE requirement_assignments MODIFY requirement_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE evidence_submissions MODIFY requirement_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        // Order matters and is the reverse of intuition: MySQL refuses to
        // drop an index while a foreign key still depends on it (errno
        // 1553), so the constraint goes first, then the index, then the
        // column. Getting this wrong leaves the rollback half-applied —
        // which the isolated rollback test caught, see
        // docs/dynamic-hierarchy-rollback.md.
        Schema::table('evidence_submissions', function (Blueprint $table) {
            $table->dropForeign(['compliance_node_id']);
            $table->dropIndex('evidence_submissions_node_status_idx');
            $table->dropColumn('compliance_node_id');
        });

        Schema::table('requirement_assignments', function (Blueprint $table) {
            $table->dropForeign(['compliance_node_id']);
            $table->dropIndex('requirement_assignments_node_status_idx');
            $table->dropColumn('compliance_node_id');
        });

        // The original schema had these NOT NULL. Restoring that constraint
        // is only possible when every row still references a Standard.
        //
        // Any assignment or submission created AFTER the cutover references
        // a ComplianceNode and has no Standard to point back at — there is
        // no correct value to invent for it. Rather than fail the rollback
        // (leaving the schema half-reverted) or fabricate ids, the column is
        // left nullable and the situation is reported. A true revert of a
        // database that has post-cutover data is a restore-from-backup,
        // which is the documented procedure — see
        // docs/dynamic-hierarchy-rollback.md.
        foreach (['requirement_assignments', 'evidence_submissions'] as $table) {
            $unbackfillable = DB::table($table)->whereNull('requirement_id')->count();

            if ($unbackfillable === 0) {
                DB::statement("ALTER TABLE {$table} MODIFY requirement_id BIGINT UNSIGNED NOT NULL");

                continue;
            }

            logger()->warning(
                "Rollback: {$table}.requirement_id left NULLable — {$unbackfillable} row(s) reference a "
                .'ComplianceNode and have no Standard to restore. Restore from backup for a full revert.'
            );
        }
    }
};
