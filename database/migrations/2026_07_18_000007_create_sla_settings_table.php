<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per ComplianceProgram. Managed by that program's Program Manager
 * (or Super Admin). See docs/sla-design.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sla_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compliance_program_id')->unique()->constrained('compliance_programs')->cascadeOnDelete();

            $table->unsignedInteger('employee_submission_sla_value')->default(5);
            $table->enum('employee_submission_sla_unit', ['hours', 'days'])->default('days');

            $table->unsignedInteger('department_manager_review_sla_value')->default(3);
            $table->enum('department_manager_review_sla_unit', ['hours', 'days'])->default('days');

            $table->unsignedInteger('auditor_review_sla_value')->default(3);
            $table->enum('auditor_review_sla_unit', ['hours', 'days'])->default('days');

            $table->unsignedInteger('program_manager_review_sla_value')->default(3);
            $table->enum('program_manager_review_sla_unit', ['hours', 'days'])->default('days');

            $table->boolean('use_business_days')->default(true);
            $table->json('working_days')->nullable(); // e.g. [0,1,2,3,4] Sun-Thu
            $table->time('working_day_start')->default('08:00:00');
            $table->time('working_day_end')->default('16:00:00');
            $table->string('timezone', 60)->default('Asia/Riyadh');

            $table->boolean('pause_sla_during_returned_revision')->default(true);
            $table->boolean('pause_sla_during_pending_extension')->default(false);
            $table->unsignedTinyInteger('warning_threshold_percentage')->default(80);
            $table->boolean('is_enabled')->default(true);

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_settings');
    }
};
