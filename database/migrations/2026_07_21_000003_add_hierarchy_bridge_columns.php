<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bridge that lets a deep ComplianceNode hierarchy (see
 * 2026_07_21_000002_create_compliance_nodes_table.php) reuse the entire
 * existing Standard-based engine unmodified: an assessable ComplianceNode
 * leaf gets a mirrored `standards` row (nullable `compliance_node_id` on
 * standards points back to it) so RequirementAssignment/EvidenceSubmission/
 * WorkflowService/SlaService/ExtensionService/notifications/dashboards/
 * reports/XLSX all keep working exactly as they do for Qiyas/Sumoud today
 * — zero changes to any of those classes. `standards.content_version_id`
 * records which framework content version a mirrored requirement came
 * from; `assessment_cycles.content_version_id` records which content
 * version a cycle was created against, so publishing a newer ECC content
 * version never silently alters a historical cycle (see
 * docs/programs/ecc/content-versioning.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('standards', function (Blueprint $table) {
            $table->foreignId('compliance_node_id')->nullable()->after('compliance_program_id')
                ->constrained('compliance_nodes')->nullOnDelete();
            $table->foreignId('content_version_id')->nullable()->after('compliance_node_id')
                ->constrained('compliance_content_versions')->nullOnDelete();
        });

        Schema::table('assessment_cycles', function (Blueprint $table) {
            $table->foreignId('content_version_id')->nullable()->after('compliance_program_id')
                ->constrained('compliance_content_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('standards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('compliance_node_id');
            $table->dropConstrainedForeignId('content_version_id');
        });

        Schema::table('assessment_cycles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('content_version_id');
        });
    }
};
