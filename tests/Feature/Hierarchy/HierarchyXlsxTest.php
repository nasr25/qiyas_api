<?php

namespace Tests\Feature\Hierarchy;

use App\Exceptions\WorkflowConflictException;
use App\Exports\Hierarchy\HierarchyMetadataSheet;
use App\Exports\Hierarchy\HierarchyNodesExport;
use App\Exports\Hierarchy\HierarchyRequirementsSheet;
use App\Exports\Hierarchy\HierarchyTemplateExport;
use App\Models\AssessmentCycle;
use App\Models\ComplianceNode;
use App\Models\ComplianceProgram;
use App\Models\ImportLog;
use App\Models\ProgramUserRole;
use App\Models\User;
use App\Services\HierarchyDefinitionService;
use App\Services\HierarchyImportService;
use App\Services\HierarchyImportValidator;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The structure-driven XLSX engine: template generation, import validation
 * and export, all derived from a program's active structure.
 *
 * The depth-parameterised tests are the core claim — the same code must
 * produce a 3-, 5- and 7-level template with no branch anywhere. The
 * security tests cover requirement 10 of the XLSX brief.
 */
class HierarchyXlsxTest extends TestCase
{
    use RefreshDatabase;

    private ComplianceProgram $program;

    private User $admin;

    private AssessmentCycle $cycle;

    private HierarchyDefinitionService $structures;

    private HierarchyImportValidator $validator;

    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->program = ComplianceProgram::where('code', 'QIYAS')->firstOrFail();
        $this->admin = $this->makeUser('super-admin');
        $this->structures = app(HierarchyDefinitionService::class);
        $this->validator = app(HierarchyImportValidator::class);

        $this->workDir = sys_get_temp_dir().'/xlsx-'.uniqid();
        mkdir($this->workDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->workDir);
        parent::tearDown();
    }

    /** Activates an N-level structure and an active cycle. */
    private function buildStructure(int $depth): array
    {
        $levels = [];
        for ($i = 1; $i <= $depth; $i++) {
            $levels[] = [
                'key' => "level_{$i}",
                'name_ar' => "المستوى {$i}",
                'name_en' => "Level {$i}",
                'is_required' => true,
                'is_assessable' => $i === $depth,
                'is_assignable' => $i === $depth,
                'accepts_evidence' => $i === $depth,
                'weight_enabled' => $i === $depth,
                'due_date_enabled' => $i === $depth,
                'instructions_enabled' => $i === $depth,
                'appears_in_reports' => true,
            ];
        }
        $this->activateStructure($this->program, $levels, $this->admin);

        $this->cycle = AssessmentCycle::create([
            'compliance_program_id' => $this->program->id,
            'structure_version_id' => $this->structures->currentStructureVersion($this->program)?->id,
            'name' => 'دورة', 'year' => 2026,
            'start_date' => now()->subMonth(), 'end_date' => now()->addMonths(6),
            'status' => 'active', 'is_current' => true, 'created_by' => $this->admin->id,
        ]);

        return array_values(collect($this->structures->levels($this->program))->all());
    }

    private function writeTemplate(array $levels, string $language = 'ar'): string
    {
        $name = "template-{$language}.xlsx";
        $path = $this->workDir.'/'.$name;
        $export = new HierarchyTemplateExport(
            $this->program, $levels,
            $this->structures->currentStructureVersion($this->program),
            $this->cycle,
            $language,
        );
        Excel::store($export, $name, 'local');
        copy(storage_path('app/private/'.$name), $path);

        return $path;
    }

    /** @return array<int, string> */
    private function visibleHeadings($book): array
    {
        return array_values(array_filter(
            $book->getSheetByName(HierarchyRequirementsSheet::SHEET_NAME)->rangeToArray('A1:CZ1')[0],
            fn ($v) => $v !== null && $v !== '',
        ));
    }

    /** @return array<int, string> */
    private function columnIdentifiers($book): array
    {
        foreach ($book->getSheetByName(HierarchyMetadataSheet::SHEET_NAME)->toArray() as $row) {
            if (($row[0] ?? null) === 'column_identifiers') {
                return explode(',', (string) $row[1]);
            }
        }

        return [];
    }

    /** Builds a workbook from explicit sheet arrays — used to forge inputs. */
    private function writeWorkbook(array $sheets, string $name = 'forged.xlsx'): string
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        foreach ($sheets as $title => $rows) {
            $sheet = $book->createSheet();
            $sheet->setTitle($title);
            $sheet->fromArray($rows, null, 'A1', true);
        }
        $path = $this->workDir.'/'.$name;
        (new XlsxWriter($book))->save($path);

        return $path;
    }

    private function metadataRows(array $columnKeys, ?int $structureVersion = null, ?string $programCode = null, ?array $levelKeys = null): array
    {
        return [
            ['key', 'value'],
            ['template_version', HierarchyTemplateExport::TEMPLATE_VERSION],
            ['schema_version', HierarchyTemplateExport::SCHEMA_VERSION],
            ['program_code', $programCode ?? $this->program->code],
            ['structure_version', (string) ($structureVersion ?? $this->structures->currentStructureVersion($this->program)->version)],
            ['generated_at', now()->toIso8601String()],
            ['generated_language', 'ar'],
            ['level_keys', implode(',', $levelKeys ?? [])],
            ['column_identifiers', implode(',', $columnKeys)],
        ];
    }

    /** A valid workbook carrying one full chain per row. */
    private function buildValidWorkbook(array $levels, int $rows = 2): string
    {
        $columns = HierarchyTemplateExport::columnsFor($levels);
        $keys = array_column($columns, 'key');

        $data = [$keys];
        for ($r = 1; $r <= $rows; $r++) {
            $row = [];
            foreach ($columns as $column) {
                $depth = array_search($column['level_key'], array_map(fn ($l) => $l->key, $levels), true) + 1;
                $row[] = match ($column['role']) {
                    'code' => 'C'.implode('.', array_fill(0, $depth, $r)),
                    'name_ar' => "اسم {$r}",
                    'name_en' => "Name {$r}",
                    default => match ($column['key']) {
                        'weight' => 5,
                        'default_due_date' => '2026-12-31',
                        default => '',
                    },
                };
            }
            $data[] = $row;
        }

        return $this->writeWorkbook([
            HierarchyRequirementsSheet::SHEET_NAME => $data,
            HierarchyMetadataSheet::SHEET_NAME => $this->metadataRows(
                $keys, null, null, array_map(fn ($l) => $l->key, $levels),
            ),
        ]);
    }

    public static function depths(): array
    {
        return ['3 levels' => [3], '5 levels' => [5], '7 levels' => [7]];
    }

    // ─── Template generation (requirements 1, 2, 3, 4, 5) ────────────────────

    #[DataProvider('depths')]
    public function test_template_columns_follow_structure_depth(int $depth): void
    {
        $levels = $this->buildStructure($depth);
        $book = IOFactory::load($this->writeTemplate($levels));

        $headers = $this->visibleHeadings($book);
        $identifiers = $this->columnIdentifiers($book);

        // Three columns per level (code + Arabic name + English name), plus
        // the deepest level's enabled attribute columns.
        $this->assertGreaterThanOrEqual($depth * 3, count($identifiers));
        // Visible headings and machine identifiers are 1:1 by position —
        // that positional pairing IS the import contract.
        $this->assertCount(count($identifiers), $headers);

        $this->assertSame('level_1_code', $identifiers[0]);
        $this->assertContains("level_{$depth}_code", $identifiers);
        $this->assertContains("level_{$depth}_name_ar", $identifiers);
    }

    /**
     * Visible headings are localized program terminology; identity lives in
     * the metadata sheet. Translating a heading must not be able to change
     * how a workbook imports.
     */
    #[DataProvider('depths')]
    public function test_identity_is_machine_identifiers_in_metadata_not_visible_headings(int $depth): void
    {
        $levels = $this->buildStructure($depth);
        $book = IOFactory::load($this->writeTemplate($levels));

        foreach ($this->columnIdentifiers($book) as $identifier) {
            $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $identifier,
                'Metadata column identifiers must be machine-safe.');
        }

        // The Arabic template's first heading is the program's own wording,
        // not a machine key.
        $headings = $this->visibleHeadings($book);
        $this->assertStringContainsString('المستوى 1', (string) $headings[0]);
        $this->assertNotSame('level_1_code', $headings[0]);
    }

    public function test_the_same_structure_exports_identical_identifiers_in_either_language(): void
    {
        $levels = $this->buildStructure(4);

        $arabic = IOFactory::load($this->writeTemplate($levels, 'ar'));
        $english = IOFactory::load($this->writeTemplate($levels, 'en'));

        // Headings differ by language…
        $this->assertNotSame($this->visibleHeadings($arabic), $this->visibleHeadings($english));
        // …but identity does not, so both import identically.
        $this->assertSame($this->columnIdentifiers($arabic), $this->columnIdentifiers($english));
    }

    public function test_template_metadata_carries_every_required_field(): void
    {
        $levels = $this->buildStructure(5);
        $book = IOFactory::load($this->writeTemplate($levels));

        $metadata = [];
        foreach ($book->getSheetByName(HierarchyMetadataSheet::SHEET_NAME)->toArray() as $row) {
            if (($row[0] ?? null) && $row[0] !== 'key') {
                $metadata[$row[0]] = $row[1];
            }
        }

        foreach (['template_version', 'schema_version', 'program_code', 'structure_version', 'generated_at', 'column_identifiers'] as $key) {
            $this->assertArrayHasKey($key, $metadata, "Metadata must include {$key}.");
            $this->assertNotSame('', (string) $metadata[$key]);
        }
        $this->assertSame($this->program->code, $metadata['program_code']);
        $this->assertSame('5', (string) $metadata['level_count']);
    }

    public function test_instructions_sheet_carries_bilingual_headings(): void
    {
        $levels = $this->buildStructure(3);
        $book = IOFactory::load($this->writeTemplate($levels));

        $rows = $book->getSheetByName('Instructions')->toArray();
        $identifiers = array_column($rows, 0);

        $this->assertContains('level_1_code', $identifiers);

        $row = collect($rows)->firstWhere(0, 'level_1_code');
        $this->assertStringContainsString('المستوى 1', (string) $row[1], 'Arabic heading must be present.');
        $this->assertStringContainsString('Level 1', (string) $row[2], 'English heading must be present.');
    }

    // ─── Import validation (requirements 6, 7) ───────────────────────────────

    #[DataProvider('depths')]
    public function test_a_valid_workbook_passes_validation_at_every_depth(int $depth): void
    {
        $levels = $this->buildStructure($depth);
        $path = $this->buildValidWorkbook($levels, 2);

        $result = $this->validator->validate($path, $this->program);

        $this->assertTrue($result['metadata_valid']);
        $this->assertSame([], $result['errors']);
        $this->assertSame(2, $result['summary']['valid_rows']);
        $this->assertCount($depth, $result['valid_rows'][0]['chain'],
            "Each row must resolve a {$depth}-link parent chain.");
    }

    public function test_a_broken_parent_chain_is_rejected(): void
    {
        $levels = $this->buildStructure(4);
        $columns = HierarchyTemplateExport::columnsFor($levels);
        $keys = array_column($columns, 'key');

        // Level 2 blank while level 3 is filled — an impossible chain.
        $row = [];
        foreach ($columns as $column) {
            $row[] = match (true) {
                $column['level_key'] === 'level_2' => '',
                $column['role'] === 'code' => 'C1',
                $column['role'] === 'name_ar' => 'اسم',
                default => '',
            };
        }

        $path = $this->writeWorkbook([
            HierarchyRequirementsSheet::SHEET_NAME => [$keys, $row],
            HierarchyMetadataSheet::SHEET_NAME => $this->metadataRows($keys),
        ]);

        $result = $this->validator->validate($path, $this->program);
        $this->assertContains('BROKEN_PARENT_CHAIN', array_column($result['errors'], 'code'));
    }

    public function test_a_template_from_a_superseded_structure_version_is_rejected(): void
    {
        $levels = $this->buildStructure(3);
        $path = $this->buildValidWorkbook($levels);

        // The manager then changes the structure, superseding v1.
        $draft = $this->structures->openDraft($this->program, $this->admin);
        $this->structures->addLevel($draft, [
            'key' => 'level_4', 'name_ar' => 'رابع', 'name_en' => 'Fourth', 'is_assessable' => true,
        ], $this->admin);
        $this->structures->activate($draft->fresh(), $this->admin, acknowledgeMigration: true);

        $result = $this->validator->validate($path, $this->program);

        $this->assertFalse($result['metadata_valid']);
        $this->assertContains('INCOMPATIBLE_STRUCTURE_VERSION', array_column($result['errors'], 'code'));
    }

    public function test_a_template_for_another_program_is_rejected(): void
    {
        $levels = $this->buildStructure(3);
        $columns = HierarchyTemplateExport::columnsFor($levels);
        $keys = array_column($columns, 'key');

        $path = $this->writeWorkbook([
            HierarchyRequirementsSheet::SHEET_NAME => [$keys],
            HierarchyMetadataSheet::SHEET_NAME => $this->metadataRows($keys, null, 'SOMEOTHER'),
        ]);

        $result = $this->validator->validate($path, $this->program);
        $this->assertContains('WRONG_PROGRAM', array_column($result['errors'], 'code'));
    }

    public function test_a_workbook_without_metadata_is_rejected(): void
    {
        $levels = $this->buildStructure(3);
        $keys = array_column(HierarchyTemplateExport::columnsFor($levels), 'key');

        $path = $this->writeWorkbook([HierarchyRequirementsSheet::SHEET_NAME => [$keys]]);

        $result = $this->validator->validate($path, $this->program);
        $this->assertContains('MISSING_METADATA', array_column($result['errors'], 'code'));
    }

    public function test_mismatched_column_identifiers_are_rejected(): void
    {
        $levels = $this->buildStructure(3);

        // Metadata declares columns that are not this structure's — the
        // identity the importer reads, so this must be refused regardless of
        // what the visible headings say.
        $path = $this->writeWorkbook([
            HierarchyRequirementsSheet::SHEET_NAME => [['a', 'b', 'c', 'd']],
            HierarchyMetadataSheet::SHEET_NAME => $this->metadataRows(['wrong_a', 'wrong_b']),
        ]);

        $result = $this->validator->validate($path, $this->program);
        $this->assertContains('COLUMN_MISMATCH', array_column($result['errors'], 'code'));
    }

    public function test_a_template_whose_level_keys_no_longer_match_is_rejected(): void
    {
        $levels = $this->buildStructure(3);
        $keys = array_column(HierarchyTemplateExport::columnsFor($levels), 'key');

        $path = $this->writeWorkbook([
            HierarchyRequirementsSheet::SHEET_NAME => [$keys],
            HierarchyMetadataSheet::SHEET_NAME => $this->metadataRows(
                $keys, null, null, ['renamed_1', 'renamed_2', 'renamed_3'],
            ),
        ]);

        $result = $this->validator->validate($path, $this->program);
        $this->assertContains('LEVEL_KEYS_MISMATCH', array_column($result['errors'], 'code'));
    }

    // ─── Security (requirement 10) ───────────────────────────────────────────

    public function test_formula_injection_payloads_are_rejected(): void
    {
        $levels = $this->buildStructure(3);
        $columns = HierarchyTemplateExport::columnsFor($levels);
        $keys = array_column($columns, 'key');

        $row = [];
        foreach ($columns as $column) {
            $row[] = match ($column['role']) {
                // A classic CSV/XLSX injection payload in a name cell.
                'name_ar' => '=cmd|\' /C calc\'!A0',
                'code' => 'C1',
                'name_en' => 'X',
                default => '',
            };
        }

        $path = $this->writeWorkbook([
            HierarchyRequirementsSheet::SHEET_NAME => [$keys, $row],
            HierarchyMetadataSheet::SHEET_NAME => $this->metadataRows($keys),
        ]);

        $result = $this->validator->validate($path, $this->program);
        $this->assertContains('FORMULA_INJECTION_REJECTED', array_column($result['errors'], 'code'));
        $this->assertSame(0, $result['summary']['valid_rows'], 'A poisoned row must never become importable.');
    }

    public function test_a_macro_enabled_workbook_is_rejected_even_when_renamed(): void
    {
        $this->buildStructure(3);

        // A .xlsx container carrying a VBA project — the marker a renamed
        // .xlsm leaves behind.
        $path = $this->workDir.'/macro.xlsx';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('xl/vbaProject.bin', 'fake-vba');
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->close();

        $result = $this->validator->validate($path, $this->program);
        $this->assertContains('MACRO_ENABLED_REJECTED', array_column($result['errors'], 'code'));
    }

    public function test_a_malformed_workbook_is_rejected_cleanly(): void
    {
        $this->buildStructure(3);
        $path = $this->workDir.'/not-a-workbook.xlsx';
        file_put_contents($path, 'this is definitely not a spreadsheet');

        $result = $this->validator->validate($path, $this->program);
        $this->assertFalse($result['metadata_valid']);
        $this->assertContains('UNREADABLE_WORKBOOK', array_column($result['errors'], 'code'));
    }

    public function test_an_oversized_file_is_rejected_before_parsing(): void
    {
        $this->buildStructure(3);
        $path = $this->workDir.'/huge.xlsx';
        file_put_contents($path, str_repeat('x', HierarchyImportValidator::MAX_FILE_BYTES + 1024));

        $result = $this->validator->validate($path, $this->program);
        $this->assertContains('FILE_TOO_LARGE', array_column($result['errors'], 'code'));
    }

    public function test_duplicate_codes_on_different_paths_are_rejected(): void
    {
        $levels = $this->buildStructure(3);
        $columns = HierarchyTemplateExport::columnsFor($levels);
        $keys = array_column($columns, 'key');

        $makeRow = function (string $rootCode) use ($columns) {
            $row = [];
            foreach ($columns as $column) {
                $row[] = match ($column['role']) {
                    // Same leaf code under two different roots.
                    'code' => $column['level_key'] === 'level_1' ? $rootCode : 'SHARED-LEAF',
                    'name_ar' => 'اسم',
                    'name_en' => 'Name',
                    default => '',
                };
            }

            return $row;
        };

        $path = $this->writeWorkbook([
            HierarchyRequirementsSheet::SHEET_NAME => [$keys, $makeRow('R1'), $makeRow('R2')],
            HierarchyMetadataSheet::SHEET_NAME => $this->metadataRows($keys),
        ]);

        $result = $this->validator->validate($path, $this->program);
        $this->assertContains('DUPLICATE_CODE', array_column($result['errors'], 'code'));
    }

    // ─── Import (requirements 8, 9) ──────────────────────────────────────────

    #[DataProvider('depths')]
    public function test_import_creates_the_whole_chain_at_every_depth(int $depth): void
    {
        $levels = $this->buildStructure($depth);
        $path = $this->buildValidWorkbook($levels, 2);

        $log = $this->importFrom($path);
        $counts = app(HierarchyImportService::class)->confirm($log, $this->program, $this->cycle, $this->admin);

        // Two independent chains of $depth nodes each.
        $this->assertSame($depth * 2, $counts['created']);
        $this->assertSame($depth * 2, ComplianceNode::where('compliance_program_id', $this->program->id)->count());

        $leaf = ComplianceNode::where('compliance_program_id', $this->program->id)
            ->where('level', $depth - 1)->first();
        $this->assertCount($depth, $leaf->pathLabels(), 'The imported leaf must carry its full path.');
    }

    public function test_an_import_with_any_error_writes_nothing(): void
    {
        $levels = $this->buildStructure(4);
        $columns = HierarchyTemplateExport::columnsFor($levels);
        $keys = array_column($columns, 'key');

        // One good row, one row with a blank required level.
        $good = [];
        $bad = [];
        foreach ($columns as $column) {
            $depth = array_search($column['level_key'], array_map(fn ($l) => $l->key, $levels), true) + 1;
            $good[] = match ($column['role']) {
                'code' => 'G'.implode('.', array_fill(0, $depth, '1')),
                'name_ar' => 'جيد', 'name_en' => 'Good', default => '',
            };
            $bad[] = match (true) {
                $column['level_key'] === 'level_2' => '',
                $column['role'] === 'code' => 'B1',
                $column['role'] === 'name_ar' => 'سيئ',
                default => '',
            };
        }

        $path = $this->writeWorkbook([
            HierarchyRequirementsSheet::SHEET_NAME => [$keys, $good, $bad],
            HierarchyMetadataSheet::SHEET_NAME => $this->metadataRows($keys),
        ]);

        $log = $this->importFrom($path);

        try {
            app(HierarchyImportService::class)->confirm($log, $this->program, $this->cycle, $this->admin);
            $this->fail('A file containing any error must be refused.');
        } catch (WorkflowConflictException $e) {
            $this->assertStringContainsString('nothing was imported', $e->getMessage());
        }

        // Requirement 8: not even the valid row may land.
        $this->assertSame(0, ComplianceNode::where('compliance_program_id', $this->program->id)->count());
    }

    public function test_reimporting_the_same_file_updates_rather_than_duplicates(): void
    {
        $levels = $this->buildStructure(3);
        $path = $this->buildValidWorkbook($levels, 1);

        $first = app(HierarchyImportService::class)->confirm($this->importFrom($path), $this->program, $this->cycle, $this->admin);
        $this->assertSame(3, $first['created']);

        $second = app(HierarchyImportService::class)->confirm($this->importFrom($path), $this->program, $this->cycle, $this->admin);
        $this->assertSame(0, $second['created']);
        $this->assertSame(3, $second['updated']);
        $this->assertSame(3, ComplianceNode::where('compliance_program_id', $this->program->id)->count());
    }

    // ─── Export (requirement 11) ─────────────────────────────────────────────

    #[DataProvider('depths')]
    public function test_export_columns_match_the_template_contract(int $depth): void
    {
        $levels = $this->buildStructure($depth);
        $path = $this->buildValidWorkbook($levels, 1);
        app(HierarchyImportService::class)->confirm($this->importFrom($path), $this->program, $this->cycle, $this->admin);

        $export = new HierarchyNodesExport($this->program, $levels, $this->cycle->id);

        $this->assertSame(
            array_column(HierarchyTemplateExport::columnsFor($levels), 'key'),
            $export->headings(),
            'Export and template must share one column contract so a round trip works.',
        );

        $rows = $export->array();
        $this->assertCount(1, $rows, 'One row per leaf node.');
        $this->assertCount(count($export->headings()), $rows[0]);
        // Every hierarchy code cell is populated to the full depth.
        for ($i = 0; $i < $depth; $i++) {
            $this->assertNotSame('', $rows[0][$i * 3], 'Level '.($i + 1).' code must be present.');
        }
    }

    // ─── API surface ─────────────────────────────────────────────────────────

    public function test_template_and_export_download_and_import_requires_program_manager(): void
    {
        $this->buildStructure(4);

        $pm = $this->makeUser('employee');
        ProgramUserRole::create([
            'compliance_program_id' => $this->program->id, 'user_id' => $pm->id,
            'role_key' => 'program-manager', 'is_active' => true,
        ]);
        $member = $this->makeUser('employee');
        ProgramUserRole::create([
            'compliance_program_id' => $this->program->id, 'user_id' => $member->id,
            'role_key' => 'employee', 'is_active' => true,
        ]);

        $this->get('/api/v1/programs/QIYAS/hierarchy-template', $this->authHeader($pm))->assertOk();
        $this->get('/api/v1/programs/QIYAS/hierarchy-export', $this->authHeader($pm))->assertOk();

        // A non-manager may read, but may not import.
        $this->postJson('/api/v1/programs/QIYAS/hierarchy-import/preview',
            ['cycle_id' => $this->cycle->id], $this->authHeader($member))->assertForbidden();
    }

    private function importFrom(string $path): ImportLog
    {
        $upload = new UploadedFile($path, 'import.xlsx', null, null, true);

        return app(HierarchyImportService::class)
            ->storeAndValidate($upload, $this->program, $this->cycle, $this->admin)['import_log'];
    }
}
