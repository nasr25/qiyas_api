<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8: versioned global branding assets (logo, favicon, dark-mode
 * logo, login/header/compact/report/email variants). Replaces the
 * previous direct-overwrite branding upload (Setting::set('branding',
 * $type, $path)) with a proper version history — the previous active
 * file is never deleted or overwritten in place, so "restore previous
 * version" is always possible. See docs/administration/branding.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branding_assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_type', 50);
            $table->unsignedInteger('version');
            $table->enum('status', ['active', 'inactive', 'superseded'])->default('inactive');
            $table->string('original_filename');
            $table->string('storage_path');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('file_hash', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at');
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('previous_version_id')->nullable()->constrained('branding_assets')->nullOnDelete();
            $table->timestamps();

            $table->unique(['asset_type', 'version'], 'branding_assets_type_version_unique');
            $table->index(['asset_type', 'status'], 'branding_assets_type_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branding_assets');
    }
};
