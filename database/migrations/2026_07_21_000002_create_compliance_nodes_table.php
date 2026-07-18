<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6: generic, self-referential, arbitrary-depth hierarchy engine.
 * Qiyas and Sumoud represent their (Perspective -> Axis -> Standard)
 * shape as free-text fields on `standards` — safe for exactly two parent
 * levels, but structurally unable to represent ECC's
 * (Main Domain -> Subdomain -> Control -> Subcontrol) shape. Rather than
 * force official ECC content into that incorrect three-level structure,
 * or add a new fixed-depth table per level, this ONE table represents
 * any depth via `parent_id`, validated against each program's own
 * `hierarchy` program-configuration category (see
 * app/Services/ComplianceNodeService.php). Qiyas/Sumoud are not migrated
 * onto this table this phase — see docs/programs/ecc/hierarchy.md,
 * "Why a bridge, not a rewrite".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compliance_program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_cycle_id')->nullable()->constrained('assessment_cycles')->nullOnDelete();
            $table->foreignId('content_version_id')->nullable()->constrained('compliance_content_versions')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('compliance_nodes')->cascadeOnDelete();
            $table->string('node_type', 50);
            $table->unsignedTinyInteger('level');
            $table->string('code', 100);
            $table->string('name_ar', 500);
            $table->string('name_en', 500)->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->text('guidance_ar')->nullable();
            $table->text('guidance_en')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_assessable')->default(false);
            $table->enum('status', ['draft', 'active', 'archived'])->default('active');
            $table->json('metadata')->nullable();
            // Bridge: an assessable node mirrors itself into `standards` so
            // the existing, already-generic assignment/evidence/review/SLA/
            // extension/notification/dashboard/report engine is reused
            // completely unmodified — see ComplianceNodeService::createAssessableNode().
            $table->foreignId('standard_id')->nullable()->constrained('standards')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['compliance_program_id', 'content_version_id', 'code'], 'compliance_nodes_program_version_code_unique');
            $table->index(['compliance_program_id', 'parent_id'], 'compliance_nodes_program_parent_idx');
            $table->index(['compliance_program_id', 'node_type'], 'compliance_nodes_program_type_idx');
            $table->index('is_assessable', 'compliance_nodes_assessable_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_nodes');
    }
};
