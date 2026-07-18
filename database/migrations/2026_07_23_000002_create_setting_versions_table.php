<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8: append-only version history for platform settings changes
 * (general, branding metadata, SMTP non-secret fields, email templates).
 * Secret fields (SMTP password) are NEVER recorded here as old_value/
 * new_value — only a secret_action ('configured'|'changed'|'removed'|
 * 'unchanged') is stored, per the brief's explicit "do not store
 * decrypted historical secrets" requirement. See
 * docs/security/secrets-management.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('setting_versions', function (Blueprint $table) {
            $table->id();
            $table->string('group', 100);
            $table->string('key', 100);
            $table->boolean('is_secret')->default(false);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->string('secret_action', 20)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['group', 'key', 'changed_at'], 'setting_versions_group_key_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setting_versions');
    }
};
