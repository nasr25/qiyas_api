<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extends assessment_cycles into the ProgramCycle shape without renaming the
 * table (renaming was judged too risky for Phase 1 — see
 * docs/multi-program-architecture.md, "Deferred technical debt"). The table
 * and model (AssessmentCycle) now function as the ProgramCycle entity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessment_cycles', function (Blueprint $table) {
            $table->foreignId('compliance_program_id')->nullable()->after('id')
                ->constrained('compliance_programs')->restrictOnDelete();
            $table->string('name_ar', 255)->nullable()->after('name');
            $table->string('name_en', 255)->nullable()->after('name_ar');
            $table->boolean('is_current')->default(false)->after('status');
            $table->json('settings')->nullable()->after('closing_notes');
            $table->foreignId('closed_by')->nullable()->after('closed_at')
                ->constrained('users')->nullOnDelete();
        });

        // Backfill: every existing cycle belongs to the QIYAS program (the
        // only program that can exist at this point in the migration chain).
        $qiyasId = DB::table('compliance_programs')->where('code', 'QIYAS')->value('id');
        if ($qiyasId) {
            DB::table('assessment_cycles')->whereNull('compliance_program_id')->update([
                'compliance_program_id' => $qiyasId,
            ]);
        }
        DB::table('assessment_cycles')
            ->update(['name_ar' => DB::raw('COALESCE(name_ar, name)')]);
        DB::table('assessment_cycles')
            ->where('status', 'active')
            ->update(['is_current' => true]);

        // Now that every row has a program, enforce the constraint going forward.
        Schema::table('assessment_cycles', function (Blueprint $table) {
            $table->unsignedBigInteger('compliance_program_id')->nullable(false)->change();
            $table->index(['compliance_program_id', 'status']);
            $table->index('is_current');
        });
    }

    public function down(): void
    {
        Schema::table('assessment_cycles', function (Blueprint $table) {
            $table->dropForeign(['compliance_program_id']);
            $table->dropForeign(['closed_by']);
            $table->dropColumn(['compliance_program_id', 'name_ar', 'name_en', 'is_current', 'settings', 'closed_by']);
        });
    }
};
