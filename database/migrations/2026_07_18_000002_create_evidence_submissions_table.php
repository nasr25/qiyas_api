<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per submission VERSION for a RequirementAssignment. A resubmission
 * after a rejection creates a new row (version_number + 1); the previous
 * version is preserved as read-only history. `status` is the single
 * authoritative workflow status — see docs/qiyas-workflow.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evidence_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compliance_program_id')->constrained('compliance_programs')->restrictOnDelete();
            $table->foreignId('program_cycle_id')->constrained('assessment_cycles')->restrictOnDelete();
            $table->foreignId('requirement_id')->constrained('standards')->restrictOnDelete();
            $table->foreignId('requirement_assignment_id')->constrained('requirement_assignments')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version_number')->default(1);
            $table->enum('status', [
                'draft', 'pending_department_manager', 'pending_auditor',
                'pending_program_manager', 'returned_for_revision', 'approved',
            ])->default('draft');
            $table->string('current_stage', 30)->default('employee');
            $table->text('employee_comment')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['requirement_assignment_id', 'version_number'], 'evidence_submissions_assignment_version_unique');
            $table->index(['compliance_program_id', 'status']);
            $table->index(['department_id', 'status']);
            $table->index('current_stage');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence_submissions');
    }
};
