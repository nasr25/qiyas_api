<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('extension_requests', function (Blueprint $table) {
            $table->foreignId('compliance_program_id')->nullable()->after('document_id')
                ->constrained('compliance_programs')->restrictOnDelete();
        });

        DB::statement('
            UPDATE extension_requests
            SET compliance_program_id = (
                SELECT compliance_program_id FROM documents WHERE documents.id = extension_requests.document_id
            )
            WHERE compliance_program_id IS NULL
        ');

        Schema::table('extension_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('compliance_program_id')->nullable(false)->change();
            $table->index('compliance_program_id');
        });
    }

    public function down(): void
    {
        Schema::table('extension_requests', function (Blueprint $table) {
            $table->dropForeign(['compliance_program_id']);
            $table->dropColumn('compliance_program_id');
        });
    }
};
