<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6: tracks a framework's official-content version (e.g. an ECC
 * controls catalog release) independently from any one cycle. A cycle
 * selects one content version (see the nullable content_version_id added
 * to assessment_cycles in the next migration) and keeps it for its entire
 * lifetime — publishing a new content version must never silently alter a
 * historical cycle's hierarchy or evidence. See
 * docs/programs/ecc/content-versioning.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_content_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compliance_program_id')->constrained()->cascadeOnDelete();
            $table->string('version_label', 100);
            $table->string('source_name')->nullable();
            $table->date('source_date')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('file_hash', 64)->nullable();
            $table->string('template_version', 20)->nullable();
            $table->enum('status', ['draft', 'active', 'superseded'])->default('draft');
            $table->date('effective_date')->nullable();
            $table->foreignId('previous_version_id')->nullable()
                ->constrained('compliance_content_versions')->nullOnDelete();
            $table->text('change_summary')->nullable();
            $table->timestamps();

            $table->unique(['compliance_program_id', 'version_label'], 'content_versions_program_label_unique');
            $table->index(['compliance_program_id', 'status'], 'content_versions_program_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_content_versions');
    }
};
