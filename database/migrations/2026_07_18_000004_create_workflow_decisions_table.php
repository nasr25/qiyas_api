<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only reviewer decision at one workflow stage. Never updated or
 * deleted — a correction is a new decision on a new EvidenceSubmission
 * version, not an edit of history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compliance_program_id')->constrained('compliance_programs')->restrictOnDelete();
            $table->foreignId('program_cycle_id')->constrained('assessment_cycles')->restrictOnDelete();
            $table->foreignId('evidence_submission_id')->constrained('evidence_submissions')->cascadeOnDelete();
            $table->enum('stage', ['department_manager', 'auditor', 'program_manager']);
            $table->enum('decision', ['approved', 'rejected']);
            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->string('reviewer_role', 50);
            $table->text('notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('decided_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['evidence_submission_id', 'stage']);
            $table->index(['reviewer_id', 'decided_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_decisions');
    }
};
