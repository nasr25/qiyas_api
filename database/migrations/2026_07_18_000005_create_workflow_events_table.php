<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only full timeline for a RequirementAssignment. Every action listed
 * in docs/qiyas-workflow.md (assignment, upload, submit, decisions,
 * extension events, SLA events, reassignment, ...) writes one row here via
 * WorkflowService — never written to directly from a controller.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compliance_program_id')->constrained('compliance_programs')->restrictOnDelete();
            $table->foreignId('program_cycle_id')->constrained('assessment_cycles')->restrictOnDelete();
            $table->foreignId('requirement_assignment_id')->constrained('requirement_assignments')->cascadeOnDelete();
            $table->foreignId('evidence_submission_id')->nullable()->constrained('evidence_submissions')->nullOnDelete();
            $table->string('event_type', 60);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role', 50)->nullable();
            $table->text('notes')->nullable();
            $table->string('old_status', 40)->nullable();
            $table->string('new_status', 40)->nullable();
            $table->unsignedInteger('evidence_version')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['requirement_assignment_id', 'created_at']);
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_events');
    }
};
