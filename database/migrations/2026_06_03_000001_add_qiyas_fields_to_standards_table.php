<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the DGA Qiyas catalog fields to the standards table.
 * Source: Qiyas_Standards_One_Sheet_V5.xlsx
 *   perspective              ← المنظور
 *   axis                     ← المحور
 *   (standard_number)        ← رقم المعيار   (existing)
 *   (name_ar)                ← المعيار        (existing)
 *   (description)            ← الهدف          (existing column reused)
 *   application_requirements ← متطلبات التطبيق
 *   evidence_documents       ← مستندات الإثبات
 *   scope                    ← النطاق
 *   related_references       ← المراجع والأنظمة المرتبطة
 *   status                   ← الحالة
 * Department (الإدارة المسؤولة) is intentionally NOT stored here — it is
 * assigned per standard in-system via the department_standard pivot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('standards', function (Blueprint $table) {
            // Source data is Arabic-only; English name is optional.
            $table->string('name_en', 500)->nullable()->change();

            $table->string('perspective', 500)->nullable()->after('cycle_id');
            $table->string('axis', 500)->nullable()->after('perspective');
            $table->text('application_requirements')->nullable()->after('description');
            $table->text('evidence_documents')->nullable()->after('application_requirements');
            $table->text('scope')->nullable()->after('evidence_documents');
            $table->text('related_references')->nullable()->after('scope');
            $table->string('status', 100)->nullable()->after('related_references');
        });
    }

    public function down(): void
    {
        Schema::table('standards', function (Blueprint $table) {
            $table->dropColumn([
                'perspective', 'axis', 'application_requirements',
                'evidence_documents', 'scope', 'related_references', 'status',
            ]);
            // Restore the original NOT NULL constraint on name_en.
            $table->string('name_en', 500)->nullable(false)->change();
        });
    }
};
