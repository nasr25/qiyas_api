<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A RequirementAssignment gives one department (and optionally one specific
 * employee) primary responsibility for a Requirement (Standard) within a
 * ProgramCycle. Changing the department creates a NEW row (status
 * `reassigned` on the old one) so assignment history is preserved; changing
 * the due date / employee / instructions updates the same active row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requirement_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compliance_program_id')->constrained('compliance_programs')->restrictOnDelete();
            $table->foreignId('program_cycle_id')->constrained('assessment_cycles')->restrictOnDelete();
            $table->foreignId('requirement_id')->constrained('standards')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('assigned_at');
            $table->date('original_due_date')->nullable();
            $table->date('effective_due_date')->nullable();
            $table->enum('status', ['active', 'reassigned', 'completed'])->default('active');
            $table->string('priority', 20)->nullable();
            $table->text('instructions_ar')->nullable();
            $table->text('instructions_en')->nullable();
            $table->foreignId('previous_assignment_id')->nullable()
                ->constrained('requirement_assignments')->nullOnDelete();
            $table->text('reassignment_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['compliance_program_id', 'program_cycle_id'], 'req_assignments_program_cycle_idx');
            $table->index(['department_id', 'status']);
            $table->index(['requirement_id', 'status']);
            $table->index('employee_id');
            $table->index('effective_due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requirement_assignments');
    }
};
