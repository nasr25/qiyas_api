<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A stage in a workflow definition. `stage_key` values are the SAME
 * internal keys the platform already uses on WorkflowDecision.stage /
 * SlaInstance.stage ('department_manager', 'auditor', 'program_manager',
 * plus the bookend keys 'employee' and 'approved') — kept unchanged
 * deliberately, per docs/compliance-engine-migration.md, rather than
 * renamed to avoid touching every existing enum column, test, and stored
 * decision record for a cosmetic difference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_stage_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_definition_id')->constrained('workflow_definitions')->cascadeOnDelete();
            $table->string('stage_key', 50);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('name_ar', 150);
            $table->string('name_en', 150);
            // Program role_key responsible at this stage; null for
            // non-reviewable bookend stages ('employee', 'approved').
            $table->string('responsible_role_key', 50)->nullable();
            $table->boolean('requires_comment')->default(false);
            $table->boolean('requires_rejection_reason')->default(false);
            $table->boolean('sla_applies')->default(true);
            $table->boolean('notifications_enabled')->default(true);
            $table->boolean('is_final')->default(false);
            $table->timestamps();

            $table->unique(['workflow_definition_id', 'stage_key'], 'workflow_stage_definitions_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_stage_definitions');
    }
};
