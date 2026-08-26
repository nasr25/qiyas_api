<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An immutable snapshot of a hierarchy definition at the moment it was
 * activated. `hierarchy_definitions` + `hierarchy_level_definitions` are
 * live, editable rows; this table is the frozen record of what the
 * structure actually looked like, so a historical cycle, a saved report or
 * an exported XLSX template can be reproduced verbatim years later even if
 * the live rows were since renamed, reordered, disabled or deleted.
 *
 * Addresses finding C5 in docs/compliance-hierarchy-audit.md: "Do not
 * silently change historical reporting because the Program Manager renamed
 * or reordered a level."
 *
 * Rows are never updated after insert — only `status` moves
 * active -> superseded. Cycles pin themselves via
 * assessment_cycles.structure_version_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_structure_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compliance_program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hierarchy_definition_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->unsignedInteger('version');
            // Full frozen definition: levels with every flag, in order.
            $table->json('snapshot');
            $table->enum('status', ['active', 'superseded'])->default('active');
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('change_summary')->nullable();
            $table->timestamps();

            $table->unique(['compliance_program_id', 'version'], 'structure_versions_program_version_unique');
            $table->index(['compliance_program_id', 'status'], 'structure_versions_program_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_structure_versions');
    }
};
