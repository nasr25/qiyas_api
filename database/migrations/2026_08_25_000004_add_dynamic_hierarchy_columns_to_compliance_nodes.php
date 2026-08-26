<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Binds ComplianceNode to its level DEFINITION rather than to a free
 * `node_type` string, and adds the per-node fields the level definition can
 * now enable (objective/weight/due date) plus per-node semantic overrides.
 *
 * `node_type` and `level` are retained for the moment: `node_type` stays as
 * a denormalised copy of the level key (useful in exports and logs) and
 * `level` as the cached 0-based depth. `hierarchy_level_id` is the
 * authoritative link from here on — see
 * docs/compliance-hierarchy-audit.md finding C4.
 *
 * The column is nullable because pre-existing ECC/NDMO sample rows predate
 * it; `compliance:verify-hierarchy` reports any node still missing a level
 * as an integrity error, and the clean test data recreated in this phase
 * always populates it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compliance_nodes', function (Blueprint $table) {
            $table->foreignId('hierarchy_level_id')->nullable()->after('parent_id')
                ->constrained('hierarchy_level_definitions')->nullOnDelete();
            $table->foreignId('structure_version_id')->nullable()->after('hierarchy_level_id')
                ->constrained('program_structure_versions')->nullOnDelete();

            $table->text('objective_ar')->nullable()->after('description_en');
            $table->text('objective_en')->nullable()->after('objective_ar');
            $table->decimal('weight', 8, 2)->nullable()->after('guidance_en');
            $table->date('default_due_date')->nullable()->after('weight');

            // NULL = inherit the level definition's flag; true/false = this
            // node deviates deliberately. Read via ComplianceNode::effective*().
            $table->boolean('is_assignable_override')->nullable()->after('is_assessable');
            $table->boolean('is_assessable_override')->nullable()->after('is_assignable_override');
            $table->boolean('accepts_evidence_override')->nullable()->after('is_assessable_override');

            $table->timestamp('archived_at')->nullable()->after('status');

            $table->index(['compliance_program_id', 'hierarchy_level_id'], 'compliance_nodes_program_level_idx');
        });

        Schema::table('assessment_cycles', function (Blueprint $table) {
            // Pins a cycle to the structure that was active when it was
            // created, so renaming/reordering levels later never rewrites
            // this cycle's historical reporting (finding C5).
            $table->foreignId('structure_version_id')->nullable()->after('content_version_id')
                ->constrained('program_structure_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assessment_cycles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('structure_version_id');
        });

        Schema::table('compliance_nodes', function (Blueprint $table) {
            // Constraints before the index they depend on (MySQL errno 1553).
            $table->dropForeign(['hierarchy_level_id']);
            $table->dropForeign(['structure_version_id']);
            $table->dropIndex('compliance_nodes_program_level_idx');
            $table->dropColumn(['hierarchy_level_id', 'structure_version_id']);
            $table->dropColumn([
                'objective_ar', 'objective_en', 'weight', 'default_due_date',
                'is_assignable_override', 'is_assessable_override',
                'accepts_evidence_override', 'archived_at',
            ]);
        });
    }
};
