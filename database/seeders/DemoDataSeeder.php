<?php

namespace Database\Seeders;

use App\Models\AssessmentCycle;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\EvidenceRequirement;
use App\Models\ExtensionRequest;
use App\Models\Standard;
use App\Models\User;
use App\Notifications\StandardAssignedNotification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Populates a fully usable demo dataset: departments, users for every role
 * (to the required counts), an ACTIVE cycle with sample standards +
 * requirements, documents across all statuses, extension requests,
 * notifications and audit logs — so dashboards/portals show real numbers.
 *
 * Idempotent and safe to re-run (used by `php artisan system:demo-data`).
 */
class DemoDataSeeder extends Seeder
{
    private const PASSWORD = 'Password123!';

    public function run(): void
    {
        // Base: departments + the 5 named accounts.
        $this->call([DepartmentsSeeder::class, TestUsersSeeder::class]);

        DB::transaction(function () {
            $departments = Department::orderBy('id')->get();

            $admin = User::where('username', 'superadmin')->first();
            $cycle = $this->ensureActiveCycle($admin);

            $standards = $this->ensureStandards($cycle, $departments, $admin?->id);

            $this->ensureDocuments($cycle, $standards);
            $this->ensureExtensionRequests();
            $this->ensureNotifications();
            $this->ensureAuditLogs();
        });
    }

    private function ensureActiveCycle(?User $admin): AssessmentCycle
    {
        return AssessmentCycle::firstOrCreate(
            ['name' => 'الدورة التجريبية 2026'],
            [
                'year' => (int) now()->year,
                'start_date' => now()->startOfYear()->toDateString(),
                'end_date' => now()->endOfYear()->toDateString(),
                'status' => 'active',
                'activated_at' => now(),
                'created_by' => $admin?->id,
            ],
        );
    }

    /** Creates ~8 sample standards with requirements, each assigned to a few departments. */
    private function ensureStandards(AssessmentCycle $cycle, $departments, ?int $adminId): Collection
    {
        $samples = [
            ['التخطيط الاستراتيجي للتحول الرقمي', 'Digital Transformation Strategy'],
            ['حوكمة البيانات', 'Data Governance'],
            ['أمن المعلومات', 'Information Security'],
            ['إدارة المخاطر', 'Risk Management'],
            ['تجربة المستفيد', 'Customer Experience'],
            ['القنوات الرقمية', 'Digital Channels'],
            ['إدارة استمرارية الأعمال', 'Business Continuity'],
            ['الابتكار والبحث', 'Innovation & Research'],
        ];

        $standards = collect();
        foreach ($samples as $i => [$ar, $en]) {
            $standard = Standard::updateOrCreate(
                ['cycle_id' => $cycle->id, 'standard_number' => '1.'.($i + 1)],
                [
                    'name_ar' => $ar,
                    'name_en' => $en,
                    'description' => "هدف المعيار: تحقيق {$ar} وفق أفضل الممارسات.",
                    'weight' => 5,
                    'is_active' => true,
                    'evidence_documents' => 'إرفاق الوثائق المعتمدة التي تثبت تطبيق المعيار.',
                ],
            );

            // Assign to 3 rotating departments.
            $assigned = $departments->slice($i % max($departments->count(), 1))->take(3)
                ->merge($departments->take(3))->unique('id')->take(3);
            $pivot = $assigned->mapWithKeys(fn ($d) => [$d->id => ['assigned_at' => now(), 'assigned_by' => $adminId]])->all();
            $standard->departments()->syncWithoutDetaching($pivot);

            // 2 requirements each.
            for ($r = 1; $r <= 2; $r++) {
                EvidenceRequirement::firstOrCreate(
                    ['standard_id' => $standard->id, 'sort_order' => $r],
                    [
                        'title_ar' => "متطلب الإثبات {$r}",
                        'title_en' => "Evidence requirement {$r}",
                        'description' => 'يرجى إرفاق المستندات الداعمة.',
                        'is_mandatory' => true,
                    ],
                );
            }

            $standards->push($standard->load('departments', 'evidenceRequirements'));
        }

        return $standards;
    }

    /** Creates documents per (requirement, assigned department) across statuses. */
    private function ensureDocuments(AssessmentCycle $cycle, $standards): void
    {
        $statuses = ['draft', 'under_review', 'approved', 'rejected', 'overdue'];
        $auditor = User::where('username', 'auditor_1')->first();

        $i = 0;
        foreach ($standards as $standard) {
            foreach ($standard->evidenceRequirements as $req) {
                foreach ($standard->departments as $dept) {
                    $status = $statuses[$i % count($statuses)];
                    $i++;

                    $employee = User::role('employee')->where('department_id', $dept->id)->inRandomOrder()->first();

                    $doc = Document::firstOrCreate(
                        ['requirement_id' => $req->id, 'department_id' => $dept->id, 'cycle_id' => $cycle->id],
                        [
                            'title' => "{$standard->name_ar} — {$req->title_ar}",
                            'status' => $status,
                            'current_version' => $status === 'draft' ? 0 : 1,
                            'submitted_by' => $status === 'draft' ? null : $employee?->id,
                            'submitted_at' => $status === 'draft' ? null : now()->subDays(rand(1, 20)),
                            'reviewed_by' => in_array($status, ['approved', 'rejected']) ? $auditor?->id : null,
                            'reviewed_at' => in_array($status, ['approved', 'rejected']) ? now()->subDays(rand(0, 5)) : null,
                            'rejection_reason' => $status === 'rejected' ? 'المستندات غير مكتملة، يرجى إعادة الرفع.' : null,
                        ],
                    );

                    if ($status !== 'draft' && $doc->versions()->count() === 0) {
                        DocumentVersion::create([
                            'document_id' => $doc->id,
                            'version_number' => 1,
                            'file_path' => 'demo/sample.pdf',
                            'original_filename' => 'sample-evidence.pdf',
                            'file_size' => 102400,
                            'file_type' => 'application/pdf',
                            'file_hash' => hash('sha256', "demo-{$doc->id}"),
                            'uploaded_by' => $employee?->id ?? $auditor?->id,
                            'uploaded_at' => now()->subDays(rand(1, 20)),
                        ]);
                    }
                }
            }
        }
    }

    private function ensureExtensionRequests(): void
    {
        if (ExtensionRequest::count() > 0) {
            return;
        }

        Document::whereIn('status', ['under_review', 'overdue'])->take(5)->get()->each(function ($doc) {
            $requester = $doc->submitted_by ?? User::role('employee')->value('id');
            if (! $requester) {
                return;
            }
            ExtensionRequest::create([
                'document_id' => $doc->id,
                'requested_by' => $requester,
                'requested_date' => now()->addDays(14)->toDateString(),
                'reason' => 'نحتاج وقتًا إضافيًا لاستكمال جمع المستندات المطلوبة.',
                'status' => 'pending',
            ]);
        });
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
                'type' => StandardAssignedNotification::class,
                'data' => [
                    'type' => 'standard_assigned',
                    'message_ar' => 'تم إسناد معيار جديد إلى إدارتك. يرجى رفع مستندات الإثبات.',
                    'message_en' => 'A new standard was assigned to your department.',
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
