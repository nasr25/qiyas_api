<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single allowed (from_stage, action) -> to_stage transition. This is
 * the data WorkflowService now reads instead of the old hardcoded
 * NEXT_STAGE/STATUS_FOR_STAGE PHP constants — see docs/workflow-engine.md.
 * Any (from_stage, action) pair with no matching row here is an invalid
 * transition and WorkflowService rejects it, exactly as the old
 * hardcoded map implicitly did by only listing valid pairs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_transition_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_definition_id')->constrained('workflow_definitions')->cascadeOnDelete();
            $table->string('from_stage_key', 50);
            $table->enum('action', ['approve', 'reject', 'submit']);
            $table->string('to_stage_key', 50);
            $table->timestamps();

            $table->unique(['workflow_definition_id', 'from_stage_key', 'action'], 'workflow_transitions_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_transition_definitions');
    }
};
