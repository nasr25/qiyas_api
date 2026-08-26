<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7: a generic responsibility-assignment structure (Data Owner, Data
 * Steward, Supporting Department, Observer, ...) — distinct from
 * requirement_assignments (which carries the one primary department +
 * optional employee that DOES have workflow authority) and distinct from
 * program_user_roles (which is what actually grants workflow authority).
 * A responsibility row is purely informational: no controller, policy, or
 * Gate check anywhere in the codebase reads `responsibility_type` to
 * authorize an action — see app/Services/ResponsibilityService.php and
 * docs/programs/ndmo/responsibilities.md, "Responsibility labels do not
 * bypass authorization."
 *
 * Never deleted on removal — is_active/end_date preserve full history for
 * audit, per the brief's explicit "removing responsibility does not
 * delete historical audit data."
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_responsibilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compliance_program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_cycle_id')->nullable()->constrained('assessment_cycles')->nullOnDelete();
            $table->foreignId('requirement_assignment_id')->constrained('requirement_assignments')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('responsibility_type', 50);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['compliance_program_id', 'requirement_assignment_id'], 'responsibilities_program_assignment_idx');
            $table->index(['requirement_assignment_id', 'responsibility_type', 'is_active'], 'responsibilities_assignment_type_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_responsibilities');
    }
};
