<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per stage occurrence. A new SlaInstance is opened when
 * responsibility moves to a stage and closed (completed_at set) when that
 * stage's actor acts or the requirement is finally approved. Historical
 * rows keep a `settings_snapshot` so later SlaSetting changes never alter
 * past measurements. See docs/sla-design.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sla_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compliance_program_id')->constrained('compliance_programs')->restrictOnDelete();
            $table->foreignId('program_cycle_id')->constrained('assessment_cycles')->restrictOnDelete();
            $table->foreignId('requirement_assignment_id')->constrained('requirement_assignments')->cascadeOnDelete();
            $table->foreignId('evidence_submission_id')->nullable()->constrained('evidence_submissions')->nullOnDelete();
            $table->enum('stage', ['employee', 'department_manager', 'auditor', 'program_manager']);
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('responsible_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('breached_at')->nullable();
            $table->enum('status', ['active', 'completed_within_sla', 'completed_after_sla', 'breached', 'cancelled', 'paused'])
                ->default('active');
            $table->unsignedInteger('elapsed_minutes')->nullable();
            $table->unsignedInteger('business_elapsed_minutes')->nullable();
            $table->json('settings_snapshot');
            $table->timestamps();

            $table->index(['requirement_assignment_id', 'stage']);
            $table->index(['status', 'due_at']);
            $table->index('responsible_department_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_instances');
    }
};
