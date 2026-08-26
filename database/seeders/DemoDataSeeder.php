<?php

namespace Database\Seeders;

use App\Models\AssessmentCycle;
use App\Models\AuditLog;
use App\Models\ComplianceProgram;
use App\Models\User;
use App\Notifications\WorkflowEventNotification;
use Database\Seeders\Concerns\NonProductionSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Populates a fully usable demo dataset: departments, users for every role
 * (to the required counts) and an ACTIVE cycle, plus notifications and
 * audit logs. Hierarchy CONTENT and workflow data are seeded separately by
 * HierarchyContentSeeder and HierarchyWorkflowSeeder, which build them from
 * each program's own structure rather than from a fixed Standard shape.
 *
 * Idempotent and safe to re-run (used by `php artisan system:demo-data`).
 */
class DemoDataSeeder extends Seeder
{
    use NonProductionSeeder;

    private const PASSWORD = 'Password123!';

    public function run(): void
    {
        $this->guardAgainstProduction();

        // Base: departments + the 5 named accounts.
        $this->call([DepartmentsSeeder::class, TestUsersSeeder::class]);

        DB::transaction(function () {
            $admin = User::where('username', 'superadmin')->first();
            $cycle = $this->ensureActiveCycle($admin);

            $this->ensureNotifications();
            $this->ensureAuditLogs();
        });
    }

    private function ensureActiveCycle(?User $admin): AssessmentCycle
    {
        // compliance_program_id is NOT NULL (see
        // 2026_07_17_000003_add_program_fields_to_assessment_cycles_table) —
        // the QIYAS ComplianceProgram row always exists by this point (it is
        // seeded by the create_compliance_programs_table migration itself,
        // not a seeder), but a genuinely fresh `migrate --seed` run failed
        // here before this fix because the value was never actually
        // supplied, only working by accident on the long-lived dev database
        // where a one-off backfill had already patched existing rows.
        $qiyas = ComplianceProgram::where('code', 'QIYAS')->firstOrFail();

        return AssessmentCycle::firstOrCreate(
            ['name' => 'الدورة التجريبية 2026'],
            [
                'compliance_program_id' => $qiyas->id,
                'year' => (int) now()->year,
                'start_date' => now()->startOfYear()->toDateString(),
                'end_date' => now()->endOfYear()->toDateString(),
                'status' => 'active',
                'is_current' => true,
                'activated_at' => now(),
                'created_by' => $admin?->id,
            ],
        );
    }

    private function ensureNotifications(): void
    {
        $employees = User::role('employee')->take(8)->get();
        foreach ($employees as $user) {
            if ($user->notifications()->count() > 0) {
                continue;
            }
            $user->notifications()->create([
                'id' => (string) Str::uuid(),
                // Program-agnostic wording: "requirement" is whatever the
                // program's assignable level is called.
                'type' => WorkflowEventNotification::class,
                'data' => [
                    'type' => 'requirement_assigned',
                    'message_ar' => 'تم إسناد متطلب جديد إلى إدارتك. يرجى رفع مستندات الإثبات.',
                    'message_en' => 'A new requirement was assigned to your department.',
                ],
                'read_at' => null,
            ]);
        }
    }

    private function ensureAuditLogs(): void
    {
        if (AuditLog::count() > 5) {
            return;
        }

        $auditor = User::where('username', 'auditor_1')->first();
        foreach (['document.approved', 'document.rejected', 'standard.created', 'user.created', 'settings.updated'] as $idx => $action) {
            AuditLog::create([
                'user_id' => $auditor?->id,
                'action' => $action,
                'description' => "إجراء تجريبي: {$action}",
                'ip_address' => '127.0.0.1',
                'user_agent' => 'DemoDataSeeder',
                'created_at' => Carbon::now()->subDays($idx),
            ]);
        }
    }
}
