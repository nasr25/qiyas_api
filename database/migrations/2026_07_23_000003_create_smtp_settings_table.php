<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8: Super-Admin-managed SMTP configuration, the database source of
 * truth ahead of the .env mail configuration (which remains the fallback
 * only when no active row exists here — see
 * app/Services/SmtpSettingsService.php and docs/security/smtp-security.md).
 * Single active configuration row (not multi-tenant) — `password_encrypted`
 * is encrypted with the application's APP_KEY via Laravel's Crypt facade
 * and is NEVER selected into an API Resource or logged; see
 * SmtpSettingsService for the one place it is ever decrypted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smtp_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_enabled')->default(false);
            $table->string('host')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->enum('encryption', ['none', 'starttls', 'tls'])->default('starttls');
            $table->boolean('auth_enabled')->default(true);
            $table->string('username')->nullable();
            $table->text('password_encrypted')->nullable();
            $table->timestamp('password_set_at')->nullable();
            $table->string('from_email')->nullable();
            $table->string('from_name_ar')->nullable();
            $table->string('from_name_en')->nullable();
            $table->string('reply_to_email')->nullable();
            $table->string('reply_to_name')->nullable();
            $table->unsignedInteger('connection_timeout')->default(10);
            $table->unsignedInteger('send_timeout')->nullable();
            $table->boolean('verify_certificate')->default(true);
            $table->boolean('queue_enabled')->default(true);
            $table->unsignedInteger('retry_count')->default(3);
            $table->unsignedInteger('retry_delay')->default(60);
            $table->string('environment_label')->nullable();
            $table->boolean('internal_relay_mode')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smtp_settings');
    }
};
