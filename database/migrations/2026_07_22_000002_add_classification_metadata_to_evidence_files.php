<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7: OPTIONAL, generic evidence-classification metadata (e.g.
 * "contains personal data", "retention category") — nullable, unpopulated
 * by default, values never hard-coded anywhere in this codebase. No
 * approved organizational classification scheme exists yet, so no UI
 * writes to this column this phase; it exists so a future authorized
 * configuration can populate it without another schema change. See
 * docs/programs/ndmo/data-classification.md.
 *
 * Deliberately NOT used for access-control decisions on its own — file
 * download authorization continues to go through the existing program/
 * cycle/department/submission-ownership checks in EvidenceSubmissionController,
 * never through this metadata (per the brief: "Do not allow the frontend
 * to decide access solely from metadata").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evidence_files', function (Blueprint $table) {
            $table->json('classification_metadata')->nullable()->after('file_hash');
        });
    }

    public function down(): void
    {
        Schema::table('evidence_files', function (Blueprint $table) {
            $table->dropColumn('classification_metadata');
        });
    }
};
