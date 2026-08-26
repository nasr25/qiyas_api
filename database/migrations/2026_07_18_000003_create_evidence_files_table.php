<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A file attached to one EvidenceSubmission version. `stored_name` is a
 * generated safe name (never the original filename) — physical storage
 * paths are never exposed to the client; downloads always go through the
 * authorized streaming endpoint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evidence_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evidence_submission_id')->constrained('evidence_submissions')->cascadeOnDelete();
            $table->string('original_name', 500);
            $table->string('stored_name', 255);
            $table->string('storage_path', 1000);
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('file_size');
            $table->string('file_hash', 64);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('uploaded_at');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['evidence_submission_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence_files');
    }
};
