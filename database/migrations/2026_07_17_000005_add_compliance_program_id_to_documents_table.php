<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('compliance_program_id')->nullable()->after('cycle_id')
                ->constrained('compliance_programs')->restrictOnDelete();
        });

        DB::statement('
            UPDATE documents
            SET compliance_program_id = (
                SELECT compliance_program_id FROM assessment_cycles WHERE assessment_cycles.id = documents.cycle_id
            )
            WHERE compliance_program_id IS NULL
        ');

        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedBigInteger('compliance_program_id')->nullable(false)->change();
            $table->index('compliance_program_id');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['compliance_program_id']);
            $table->dropColumn('compliance_program_id');
        });
    }
};
