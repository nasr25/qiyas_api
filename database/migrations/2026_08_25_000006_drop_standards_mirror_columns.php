<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror removal, step 2 of 2 (cleanup).
 *
 * Drops the columns that existed only to bridge ComplianceNode to the
 * two-level `standards` projection (audit findings C2 and C6):
 *
 *   compliance_nodes.standard_id        — the forward link
 *   standards.compliance_node_id        — the back-reference no code ever wrote
 *   requirement_assignments.requirement_id
 *   evidence_submissions.requirement_id — replaced by compliance_node_id
 *
 * Run only after `compliance:verify-hierarchy` reports zero rows relying on
 * them, which it now checks explicitly. Existing compliance content is
 * disposable in this phase, so no data is preserved.
 *
 * The `standards` TABLE itself is deliberately left in place: it still
 * backs the legacy Qiyas document path (Standard/EvidenceRequirement/
 * Document), which the dynamic dashboards and reports work will retire.
 * Dropping it here would break that path before its replacement exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded per column: MySQL DDL is not transactional, so a migration
        // that drops several columns can be left half-applied by an error in
        // the middle. These checks make a re-run safe rather than requiring a
        // manual repair.
        if (Schema::hasColumn('compliance_nodes', 'standard_id')) {
            Schema::table('compliance_nodes', function (Blueprint $table) {
                $table->dropConstrainedForeignId('standard_id');
            });
        }

        if (Schema::hasColumn('standards', 'compliance_node_id')) {
            Schema::table('standards', function (Blueprint $table) {
                $table->dropConstrainedForeignId('compliance_node_id');
            });
        }

        if (Schema::hasColumn('requirement_assignments', 'requirement_id')) {
            Schema::table('requirement_assignments', function (Blueprint $table) {
                // Order matters: MySQL needs the index while the foreign key
                // still references it, so the constraint goes first.
                $table->dropForeign(['requirement_id']);
                $table->dropIndex('requirement_assignments_requirement_id_status_index');
                $table->dropColumn('requirement_id');
            });
        }

        if (Schema::hasColumn('evidence_submissions', 'requirement_id')) {
            Schema::table('evidence_submissions', function (Blueprint $table) {
                $table->dropConstrainedForeignId('requirement_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('evidence_submissions', function (Blueprint $table) {
            $table->foreignId('requirement_id')->nullable()->constrained('standards')->restrictOnDelete();
        });

        Schema::table('requirement_assignments', function (Blueprint $table) {
            $table->foreignId('requirement_id')->nullable()->constrained('standards')->cascadeOnDelete();
            $table->index(['requirement_id', 'status']);
        });

        Schema::table('standards', function (Blueprint $table) {
            $table->foreignId('compliance_node_id')->nullable()->constrained('compliance_nodes')->nullOnDelete();
        });

        Schema::table('compliance_nodes', function (Blueprint $table) {
            $table->foreignId('standard_id')->nullable()->constrained('standards')->nullOnDelete();
        });
    }
};
