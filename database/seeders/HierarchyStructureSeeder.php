<?php

namespace Database\Seeders;

use App\Models\ComplianceProgram;
use App\Models\User;
use App\Services\HierarchyDefinitionService;
use Illuminate\Database\Seeder;

/**
 * Seeds each program's own hierarchy STRUCTURE through the same public
 * service path a Program Manager uses from the Structure Settings screen —
 * openDraft() -> addLevel() -> activate(). Nothing here bypasses validation
 * or the structure-version snapshot, so if this seeder runs, the
 * manager-facing flow demonstrably works.
 *
 * The four shapes are deliberately different depths (3 / 5 / 5 / 6) to
 * prove the engine carries no fixed-depth assumption — see
 * docs/compliance-hierarchy-audit.md, findings C2 and H4.
 */
class HierarchyStructureSeeder extends Seeder
{
    public function run(): void
    {
        $actor = User::where('username', 'superadmin')->first();
        if (! $actor) {
            return;
        }

        foreach (self::structures() as $code => $structure) {
            $program = ComplianceProgram::where('code', $code)->first();
            if (! $program) {
                continue;
            }

            $service = app(HierarchyDefinitionService::class);

            // Idempotent: leave an already-configured program alone.
            if ($service->activeDefinition($program)) {
                continue;
            }

            $draft = $service->openDraft($program, $actor);
            $draft->update([
                'name_ar' => $structure['name_ar'],
                'name_en' => $structure['name_en'],
                'change_summary' => 'Initial dynamic hierarchy structure.',
            ]);

            foreach ($structure['levels'] as $level) {
                $service->addLevel($draft, $level, $actor);
            }

            $service->activate($draft->fresh(), $actor);
        }
    }

    /**
     * Grouping levels carry no work; they exist to aggregate. Assessable
     * levels enter the workflow. Assignable levels can be given to a
     * department. Evidence-bearing levels collect files. All three are
     * data here, not code — which is the entire point of the engine.
     */
    public static function structures(): array
    {
        return [
            // ── 3 levels ────────────────────────────────────────────────
            'SUMOUD' => [
                'name_ar' => 'هيكل برنامج صمود',
                'name_en' => 'Sumoud Programme Structure',
                'levels' => [
                    self::grouping('domain', 'المجال', 'Domain', 'المجالات', 'Domains'),
                    self::grouping('category', 'الفئة', 'Category', 'الفئات', 'Categories'),
                    self::workItem('requirement', 'المتطلب', 'Requirement', 'المتطلبات', 'Requirements'),
                ],
            ],

            // ── 5 levels ────────────────────────────────────────────────
            'QIYAS' => [
                'name_ar' => 'هيكل قياس للتحول الرقمي',
                'name_en' => 'Qiyas Digital Transformation Structure',
                'levels' => [
                    self::grouping('perspective', 'المنظور', 'Perspective', 'المناظير', 'Perspectives'),
                    self::grouping('axis', 'المحور', 'Axis', 'المحاور', 'Axes'),
                    self::assessableGroup('criterion', 'المعيار', 'Criterion', 'المعايير', 'Criteria'),
                    self::workItem('application_requirement', 'متطلب التطبيق', 'Application Requirement', 'متطلبات التطبيق', 'Application Requirements'),
                    self::evidenceLeaf('evidence_requirement', 'متطلب الإثبات', 'Evidence Requirement', 'متطلبات الإثبات', 'Evidence Requirements'),
                ],
            ],

            // ── 5 levels ────────────────────────────────────────────────
            'ECC' => [
                'name_ar' => 'هيكل الضوابط الأساسية للأمن السيبراني',
                'name_en' => 'Essential Cybersecurity Controls Structure',
                'levels' => [
                    self::grouping('main_domain', 'المجال الرئيسي', 'Main Domain', 'المجالات الرئيسية', 'Main Domains'),
                    self::grouping('subdomain', 'المجال الفرعي', 'Subdomain', 'المجالات الفرعية', 'Subdomains'),
                    self::assessableGroup('control', 'الضابط', 'Control', 'الضوابط', 'Controls'),
                    self::workItem('subcontrol', 'الضابط الفرعي', 'Subcontrol', 'الضوابط الفرعية', 'Subcontrols'),
                    self::evidenceLeaf('implementation_requirement', 'متطلب التطبيق', 'Implementation Requirement', 'متطلبات التطبيق', 'Implementation Requirements'),
                ],
            ],

            // ── 6 levels ────────────────────────────────────────────────
            'NDMO' => [
                'name_ar' => 'هيكل مكتب إدارة البيانات الوطنية',
                'name_en' => 'National Data Management Office Structure',
                'levels' => [
                    self::grouping('domain', 'المجال', 'Domain', 'المجالات', 'Domains'),
                    self::grouping('policy', 'السياسة', 'Policy', 'السياسات', 'Policies'),
                    self::grouping('standard', 'المعيار', 'Standard', 'المعايير', 'Standards'),
                    self::assessableGroup('requirement', 'المتطلب', 'Requirement', 'المتطلبات', 'Requirements'),
                    self::workItem('subrequirement', 'المتطلب الفرعي', 'Subrequirement', 'المتطلبات الفرعية', 'Subrequirements'),
                    self::evidenceLeaf('control_activity', 'إجراء الضبط', 'Control Activity', 'إجراءات الضبط', 'Control Activities'),
                ],
            ],
        ];
    }

    /** Pure aggregation: appears in dashboards, reports and filters; holds no work. */
    private static function grouping(string $key, string $ar, string $en, string $pluralAr, string $pluralEn): array
    {
        return [
            'key' => $key, 'name_ar' => $ar, 'name_en' => $en,
            'plural_name_ar' => $pluralAr, 'plural_name_en' => $pluralEn,
            'is_required' => true, 'is_active' => true, 'allow_children' => true,
            'is_assignable' => false, 'is_assessable' => false, 'accepts_evidence' => false,
            'appears_in_dashboard' => true, 'appears_in_reports' => true,
            'appears_in_filters' => true, 'appears_in_breadcrumb' => true,
            'code_required' => true, 'description_enabled' => true,
            'objective_enabled' => false, 'weight_enabled' => false,
            'due_date_enabled' => false, 'instructions_enabled' => false,
        ];
    }

    /** Assessable and assignable, but still groups children (e.g. a Criterion / Control). */
    private static function assessableGroup(string $key, string $ar, string $en, string $pluralAr, string $pluralEn): array
    {
        return [
            ...self::grouping($key, $ar, $en, $pluralAr, $pluralEn),
            'is_assignable' => true, 'is_assessable' => true, 'accepts_evidence' => false,
            'objective_enabled' => true, 'weight_enabled' => true, 'due_date_enabled' => true,
        ];
    }

    /** The main unit of work: assigned to a department and collects evidence. */
    private static function workItem(string $key, string $ar, string $en, string $pluralAr, string $pluralEn): array
    {
        return [
            ...self::assessableGroup($key, $ar, $en, $pluralAr, $pluralEn),
            'accepts_evidence' => true, 'instructions_enabled' => true,
            'appears_in_dashboard' => false,
        ];
    }

    /** Deepest level: collects evidence, never assigned independently, no children. */
    private static function evidenceLeaf(string $key, string $ar, string $en, string $pluralAr, string $pluralEn): array
    {
        return [
            ...self::workItem($key, $ar, $en, $pluralAr, $pluralEn),
            'is_required' => false, 'allow_children' => false,
            'is_assignable' => false,
            'appears_in_dashboard' => false, 'appears_in_filters' => false,
            'weight_enabled' => false,
        ];
    }
}
