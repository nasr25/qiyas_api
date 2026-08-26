<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per level of one hierarchy definition — e.g. Qiyas's
 * Perspective/Axis/Criterion/Application Requirement/Evidence Requirement,
 * or NDMO's six-level Domain/Policy/Standard/Requirement/Subrequirement/…
 *
 * This table is what makes the engine dynamic: depth is a row count, not a
 * schema fact, and every behaviour the platform previously hard-coded
 * (which level may be assigned, which accepts evidence, which appears in a
 * dashboard, a report, a filter, a breadcrumb, which form fields render)
 * is a column here rather than a PHP branch. See
 * docs/compliance-hierarchy-audit.md findings C4, H1, H2, H3, H6, H7.
 *
 * Deliberate deviations from the brief's suggested field list, per its own
 * instruction not to add unused fields blindly:
 *   - `singular_name_*` is omitted: `name_*` already IS the singular form
 *     ("Perspective"), and `plural_name_*` covers list headings
 *     ("Perspectives"). A third naming pair would never differ from the first.
 *   - `sort_order` is omitted: for a LEVEL the only ordering axis is depth,
 *     which `level_order` already expresses. (Sibling ordering belongs to
 *     nodes, where `compliance_nodes.sort_order` already exists.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hierarchy_level_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hierarchy_definition_id')->constrained()->cascadeOnDelete();
            // Denormalised so every level query can be program-scoped (and
            // indexed) without a join — the same pattern the platform already
            // uses for compliance_program_id on standards/documents.
            $table->foreignId('compliance_program_id')->constrained()->cascadeOnDelete();

            $table->string('key', 50);
            $table->string('name_ar', 100);
            $table->string('name_en', 100);
            $table->string('plural_name_ar', 100)->nullable();
            $table->string('plural_name_en', 100)->nullable();

            $table->unsignedInteger('level_order');
            $table->foreignId('parent_level_id')->nullable()
                ->constrained('hierarchy_level_definitions')->nullOnDelete();

            // ─── Structural semantics ───────────────────────────────────
            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('allow_children')->default(true);

            // ─── Behavioural semantics (replace hard-coded PHP branches) ─
            $table->boolean('is_assignable')->default(false);
            $table->boolean('is_assessable')->default(false);
            $table->boolean('accepts_evidence')->default(false);

            // ─── Presentation surfaces ──────────────────────────────────
            $table->boolean('appears_in_dashboard')->default(false);
            $table->boolean('appears_in_reports')->default(false);
            $table->boolean('appears_in_filters')->default(false);
            $table->boolean('appears_in_breadcrumb')->default(true);

            // ─── Dynamic form field enablement ──────────────────────────
            $table->boolean('code_required')->default(true);
            $table->boolean('description_enabled')->default(true);
            $table->boolean('objective_enabled')->default(false);
            $table->boolean('weight_enabled')->default(false);
            $table->boolean('due_date_enabled')->default(false);
            $table->boolean('instructions_enabled')->default(false);

            $table->string('icon', 50)->nullable();
            $table->json('metadata_schema')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['hierarchy_definition_id', 'key'], 'hierarchy_levels_definition_key_unique');
            $table->unique(['hierarchy_definition_id', 'level_order'], 'hierarchy_levels_definition_order_unique');
            $table->index(['compliance_program_id', 'is_active'], 'hierarchy_levels_program_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hierarchy_level_definitions');
    }
};
