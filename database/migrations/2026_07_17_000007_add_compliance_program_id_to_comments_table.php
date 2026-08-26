<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * comments is polymorphic (commentable_type/commentable_id). Today the only
 * commentable is Document, so backfill via that join; compliance_program_id
 * stays nullable here (unlike standards/documents/extension_requests) since a
 * future commentable type might not carry a program at all. New rows are
 * auto-stamped by the Comment model's creating hook when the commentable
 * exposes compliance_program_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->foreignId('compliance_program_id')->nullable()->after('commentable_id')
                ->constrained('compliance_programs')->nullOnDelete();
        });

        DB::statement("
            UPDATE comments
            SET compliance_program_id = (
                SELECT compliance_program_id FROM documents WHERE documents.id = comments.commentable_id
            )
            WHERE compliance_program_id IS NULL AND commentable_type = 'App\\\\Models\\\\Document'
        ");

        Schema::table('comments', function (Blueprint $table) {
            $table->index('compliance_program_id');
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropForeign(['compliance_program_id']);
            $table->dropColumn('compliance_program_id');
        });
    }
};
