<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 reuses the existing generic ExtensionRequest entity (Phase 1)
 * rather than introducing a parallel Qiyas-specific table — its shape
 * (Employee requests, Auditor decides) already matched the required
 * behavior. `document_id` becomes nullable since new Phase 2 requests
 * attach to a RequirementAssignment instead of the legacy Document model.
 *
 * Uses portable Schema Blueprint methods throughout (no raw dialect-specific
 * SQL) so this runs correctly on both MySQL (dev/prod) and SQLite (tests).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('extension_requests', function (Blueprint $table) {
            $table->foreignId('program_cycle_id')->nullable()->after('compliance_program_id')
                ->constrained('assessment_cycles')->nullOnDelete();
            $table->foreignId('requirement_assignment_id')->nullable()->after('document_id')
                ->constrained('requirement_assignments')->cascadeOnDelete();
            $table->date('current_due_date')->nullable()->after('requested_date');
            $table->date('requested_due_date')->nullable()->after('current_due_date');
        });

        Schema::table('extension_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('document_id')->nullable()->change();
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending')->change();
        });
    }

    public function down(): void
    {
        DB::table('extension_requests')->where('status', 'cancelled')->update(['status' => 'rejected']);

        Schema::table('extension_requests', function (Blueprint $table) {
            $table->dropForeign(['program_cycle_id']);
            $table->dropForeign(['requirement_assignment_id']);
            $table->dropColumn(['program_cycle_id', 'requirement_assignment_id', 'current_due_date', 'requested_due_date']);
        });

        Schema::table('extension_requests', function (Blueprint $table) {
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->change();
            $table->unsignedBigInteger('document_id')->nullable(false)->change();
        });
    }
};
