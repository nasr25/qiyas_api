<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A program's hierarchy definition — the replacement for the `hierarchy`
 * program-configuration JSON blob audited in
 * docs/compliance-hierarchy-audit.md (finding C4). Each row is one
 * *revision* of a program's structure: a Program Manager edits a `draft`,
 * activates it (making it `active` and superseding the previous one), and
 * the superseded revision is retained so historical cycles keep resolving
 * the level names, order and semantics that were in force when they ran
 * (finding C5).
 *
 * Exactly one row per program may be `active` at a time; this is enforced
 * transactionally in HierarchyDefinitionService::activate() (MySQL has no
 * partial unique index), and asserted by `compliance:verify-hierarchy`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hierarchy_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compliance_program_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('name_ar', 200)->nullable();
            $table->string('name_en', 200)->nullable();
            $table->enum('status', ['draft', 'active', 'superseded', 'archived'])->default('draft');
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('change_summary')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['compliance_program_id', 'version'], 'hierarchy_definitions_program_version_unique');
            $table->index(['compliance_program_id', 'status'], 'hierarchy_definitions_program_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hierarchy_definitions');
    }
};
