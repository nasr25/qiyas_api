<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * audit_logs is polymorphic across many model types (model_type/model_id) and
 * predates the program concept entirely, so a full historical backfill is not
 * attempted here — only a best-effort pass for the model types whose program
 * is unambiguously derivable. Rows that stay NULL (e.g. auth.login events
 * with no model, or historical rows for types not covered below) are expected
 * and do not indicate a data problem; see docs/qiyas-migration-plan.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('compliance_program_id')->nullable()->after('department_id')
                ->constrained('compliance_programs')->nullOnDelete();
        });

        DB::statement("
            UPDATE audit_logs
            SET compliance_program_id = (
                SELECT compliance_program_id FROM assessment_cycles WHERE assessment_cycles.id = audit_logs.model_id
            )
            WHERE compliance_program_id IS NULL AND model_type = 'App\\\\Models\\\\AssessmentCycle'
        ");

        DB::statement("
            UPDATE audit_logs
            SET compliance_program_id = (
                SELECT compliance_program_id FROM standards WHERE standards.id = audit_logs.model_id
            )
            WHERE compliance_program_id IS NULL AND model_type = 'App\\\\Models\\\\Standard'
        ");

        DB::statement("
            UPDATE audit_logs
            SET compliance_program_id = (
                SELECT compliance_program_id FROM documents WHERE documents.id = audit_logs.model_id
            )
            WHERE compliance_program_id IS NULL AND model_type = 'App\\\\Models\\\\Document'
        ");

        DB::statement("
            UPDATE audit_logs
            SET compliance_program_id = (
                SELECT compliance_program_id FROM extension_requests WHERE extension_requests.id = audit_logs.model_id
            )
            WHERE compliance_program_id IS NULL AND model_type = 'App\\\\Models\\\\ExtensionRequest'
        ");

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index('compliance_program_id');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign(['compliance_program_id']);
            $table->dropColumn('compliance_program_id');
        });
    }
};
