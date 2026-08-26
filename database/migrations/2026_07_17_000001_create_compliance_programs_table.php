<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Top-level entity for the multi-program compliance platform.
 * Every existing Qiyas cycle/standard/document ultimately belongs to one
 * ComplianceProgram row (seeded as QIYAS in the next migration's data pass).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_programs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name_ar', 255);
            $table->string('name_en', 255);
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('logo')->nullable();
            $table->string('icon', 100)->nullable();
            $table->enum('status', ['draft', 'active', 'inactive'])->default('draft');
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('primary_color', 20)->nullable();
            $table->string('secondary_color', 20)->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'status']);
            $table->index('sort_order');
        });

        // Baseline data, not demo data: the QIYAS program must exist before any
        // later migration can backfill compliance_program_id on existing rows,
        // and before the app can boot in a usable state. Inserted here (schema
        // migration) rather than a seeder so it is guaranteed to run exactly
        // once, in order, on every environment including this pre-populated
        // database.
        $terminology = [
            'domain' => ['ar' => 'المنظور', 'en' => 'Perspective'],
            'category' => ['ar' => 'المحور', 'en' => 'Axis'],
            'requirement' => ['ar' => 'المعيار', 'en' => 'Standard'],
            'evidence' => ['ar' => 'مستند الإثبات', 'en' => 'Evidence Document'],
            'cycle' => ['ar' => 'دورة القياس', 'en' => 'Qiyas Cycle'],
        ];

        DB::table('compliance_programs')->updateOrInsert(
            ['code' => 'QIYAS'],
            [
                'name_ar' => 'قياس',
                'name_en' => 'Qiyas',
                'description_ar' => 'برنامج قياس لمتطلبات التحول الرقمي للجهات الحكومية',
                'description_en' => 'Qiyas digital transformation compliance program for government entities',
                'icon' => 'shield-check',
                'status' => 'active',
                'sort_order' => 1,
                'primary_color' => '#14532d',
                'secondary_color' => '#0f766e',
                'settings' => json_encode(['terminology' => $terminology]),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_programs');
    }
};
