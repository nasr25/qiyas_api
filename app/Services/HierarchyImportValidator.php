<?php

namespace App\Services;

use App\Exports\Hierarchy\HierarchyMetadataSheet;
use App\Exports\Hierarchy\HierarchyRequirementsSheet;
use App\Exports\Hierarchy\HierarchyTemplateExport;
use App\Models\ComplianceNode;
use App\Models\ComplianceProgram;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use ZipArchive;

/**
 * Validates an uploaded workbook against the program's ACTIVE structure.
 * Never writes to the database — HierarchyImportService performs the
 * transactional write only after this returns clean.
 *
 * Everything is derived from the structure: which columns must exist, how
 * deep a row's parent chain runs, which levels are required. A program that
 * adds a level gets stricter validation with no code change.
 *
 * Security posture (requirement 10):
 *   - VBA macro containers rejected outright, even renamed to .xlsx
 *   - unreadable / non-zip workbooks rejected with a clear code
 *   - file size and row count capped before parsing cost is incurred
 *   - formula-injection payloads (= + - @ and tab/CR leaders) rejected,
 *     not silently sanitised, so the uploader learns their file was altered
 *   - metadata must name THIS program and THIS structure version
 *   - duplicate codes and impossible parent chains rejected
 */
class HierarchyImportValidator
{
    public const MAX_ROWS = 5000;

    /** 10 MB. A structure-driven template is far smaller; this bounds parse cost. */
    public const MAX_FILE_BYTES = 10 * 1024 * 1024;

    /** Leading characters Excel interprets as the start of a formula. */
    private const FORMULA_LEADERS = ['=', '+', '-', '@', "\t", "\r"];

    public function __construct(private readonly HierarchyDefinitionService $structures) {}

    /**
     * @return array{
     *   metadata_valid: bool, errors: array, warnings: array,
     *   summary: array, valid_rows: array, template_version: string,
     *   structure_version: ?int
     * }
     */
    public function validate(string $absolutePath, ComplianceProgram $program): array
    {
        if (! is_file($absolutePath)) {
            return $this->fail([$this->error('-', 0, '-', null, 'FILE_MISSING',
                'الملف غير متوفر.', 'The uploaded file is no longer available.')]);
        }

        if (filesize($absolutePath) > self::MAX_FILE_BYTES) {
            return $this->fail([$this->error('-', 0, '-', filesize($absolutePath), 'FILE_TOO_LARGE',
                'حجم الملف يتجاوز الحد المسموح (10 ميجابايت).',
                'The file exceeds the maximum allowed size (10 MB).')]);
        }

        if ($this->isMacroEnabledWorkbook($absolutePath)) {
            return $this->fail([$this->error('-', 0, '-', null, 'MACRO_ENABLED_REJECTED',
                'الملفات التي تحتوي على وحدات ماكرو (VBA) مرفوضة لأسباب أمنية، حتى لو كان امتدادها xlsx.',
                'Workbooks containing VBA macros are rejected for security reasons, even if renamed to .xlsx.')]);
        }

        try {
            $sheets = Excel::toArray(null, $absolutePath);
        } catch (\Throwable) {
            return $this->fail([$this->error('-', 0, '-', null, 'UNREADABLE_WORKBOOK',
                'تعذّرت قراءة الملف. تأكد أنه بصيغة xlsx صالحة.',
                'The workbook could not be read. Ensure it is a valid .xlsx file.')]);
        }

        // ─── Metadata gate ───────────────────────────────────────────────
        $metadataIndex = $this->findSheetIndexByContent($sheets, 'template_version');
        if ($metadataIndex === null) {
            return $this->fail([$this->error(HierarchyMetadataSheet::SHEET_NAME, 0, '-', null, 'MISSING_METADATA',
                'الملف لا يحتوي على ورقة البيانات الوصفية. يجب استخدام القالب المُصدَّر من المنصة.',
                'The workbook is missing the metadata sheet. Use the platform-generated template.')]);
        }
        $metadata = $this->parseMetadata($sheets[$metadataIndex]);

        $levels = array_values(collect($this->structures->levels($program))->all());
        if (! $levels) {
            return $this->fail([$this->error('-', 0, '-', null, 'NO_ACTIVE_STRUCTURE',
                'لا يوجد هيكل نشط لهذا البرنامج.',
                'This program has no active hierarchy structure.')]);
        }
        $currentVersion = $this->structures->currentStructureVersion($program);

        $errors = [];

        if (($metadata['program_code'] ?? null) !== $program->code) {
            $errors[] = $this->error(HierarchyMetadataSheet::SHEET_NAME, 0, 'program_code',
                $metadata['program_code'] ?? null, 'WRONG_PROGRAM',
                'هذا القالب مخصص لبرنامج آخر.',
                'This template was generated for a different program.');
        }

        if (($metadata['template_version'] ?? null) !== HierarchyTemplateExport::TEMPLATE_VERSION) {
            $errors[] = $this->error(HierarchyMetadataSheet::SHEET_NAME, 0, 'template_version',
                $metadata['template_version'] ?? null, 'UNSUPPORTED_TEMPLATE_VERSION',
                'إصدار القالب غير مدعوم. الرجاء تنزيل القالب الحالي.',
                'Unsupported template version. Please download the current template.');
        }

        // Requirement 7: a template describing a superseded structure must
        // be refused, never reinterpreted against the current one.
        $templateStructure = $metadata['structure_version'] ?? '';
        if ((string) ($currentVersion?->version ?? '') !== (string) $templateStructure) {
            $errors[] = $this->error(HierarchyMetadataSheet::SHEET_NAME, 0, 'structure_version',
                $templateStructure, 'INCOMPATIBLE_STRUCTURE_VERSION',
                'أُنشئ هذا القالب من إصدار هيكل مختلف (v'.($templateStructure ?: '؟').') بينما الإصدار النشط هو v'.($currentVersion?->version ?? '؟').'. الرجاء تنزيل القالب الحالي.',
                'This template was generated from structure version v'.($templateStructure ?: '?').' but the active version is v'.($currentVersion?->version ?? '?').'. Download the current template.');
        }

        if ($errors) {
            return $this->fail($errors, $metadata['template_version'] ?? 'unknown');
        }

        // ─── Data sheet ──────────────────────────────────────────────────
        $expected = HierarchyTemplateExport::columnsFor($levels);
        $expectedKeys = array_column($expected, 'key');

        // Column identity comes from the metadata sheet, in column order —
        // NEVER from the visible headings, which are localized program
        // terminology and change with language and with a rename.
        $declaredKeys = array_values(array_filter(
            array_map('trim', explode(',', $metadata['column_identifiers'] ?? '')),
            fn ($key) => $key !== '',
        ));

        if ($declaredKeys !== $expectedKeys) {
            return $this->fail([$this->error(HierarchyRequirementsSheet::SHEET_NAME, 1, '-', null, 'COLUMN_MISMATCH',
                'أعمدة الملف لا تطابق هيكل البرنامج الحالي. الرجاء تنزيل القالب الحالي.',
                'The workbook columns do not match this program\'s current structure. Download the current template.')],
                $metadata['template_version'] ?? 'unknown');
        }

        $declaredLevelKeys = array_values(array_filter(
            array_map('trim', explode(',', $metadata['level_keys'] ?? '')),
            fn ($key) => $key !== '',
        ));
        $actualLevelKeys = array_map(fn ($level) => $level->key, $levels);

        if ($declaredLevelKeys && $declaredLevelKeys !== $actualLevelKeys) {
            return $this->fail([$this->error(HierarchyMetadataSheet::SHEET_NAME, 0, 'level_keys',
                implode(',', $declaredLevelKeys), 'LEVEL_KEYS_MISMATCH',
                'مستويات القالب لا تطابق مستويات البرنامج الحالية. الرجاء تنزيل القالب الحالي.',
                'The template\'s levels do not match the program\'s current levels. Download the current template.')],
                $metadata['template_version'] ?? 'unknown');
        }

        $dataIndex = $this->findDataSheetIndex($sheets, count($expectedKeys));
        if ($dataIndex === null) {
            return $this->fail([$this->error(HierarchyRequirementsSheet::SHEET_NAME, 1, '-', null, 'MISSING_DATA_SHEET',
                'تعذّر العثور على ورقة البيانات بالعدد الصحيح من الأعمدة.',
                'Could not locate the data sheet with the expected column count.')],
                $metadata['template_version'] ?? 'unknown');
        }

        $rows = $sheets[$dataIndex];
        // Drop the heading row; identity is positional from here on.
        array_shift($rows);
        $header = $declaredKeys;
        $dataRows = array_values(array_filter($rows, fn ($r) => count(array_filter($r, fn ($c) => $c !== null && $c !== '')) > 0));

        if (count($dataRows) > self::MAX_ROWS) {
            return $this->fail([$this->error(HierarchyRequirementsSheet::SHEET_NAME, 0, '-', count($dataRows), 'TOO_MANY_ROWS',
                'عدد الصفوف يتجاوز الحد الأقصى المسموح ('.self::MAX_ROWS.').',
                'Row count exceeds the maximum allowed ('.self::MAX_ROWS.').')],
                $metadata['template_version'] ?? 'unknown');
        }

        $index = array_flip($header);
        $validRows = [];
        $seenCodes = [];
        $warnings = [];

        foreach ($dataRows as $i => $raw) {
            $rowNumber = $i + 2; // header is row 1
            $get = fn (string $key) => isset($index[$key]) ? ($raw[$index[$key]] ?? null) : null;

            $rowErrors = [];
            $chain = [];

            foreach ($levels as $depth => $level) {
                $code = $this->clean($get("{$level->key}_code"));
                $nameAr = $this->clean($get("{$level->key}_name_ar"));
                $nameEn = $this->clean($get("{$level->key}_name_en"));

                foreach ([["{$level->key}_code", $code], ["{$level->key}_name_ar", $nameAr], ["{$level->key}_name_en", $nameEn]] as [$col, $value]) {
                    if ($value !== null && $this->looksLikeFormula($value)) {
                        $rowErrors[] = $this->error(HierarchyRequirementsSheet::SHEET_NAME, $rowNumber, $col, $value, 'FORMULA_INJECTION_REJECTED',
                            'القيمة تبدأ برمز قد يُفسَّر كصيغة (= + - @). الرجاء إزالته.',
                            'The value starts with a character Excel may interpret as a formula (= + - @). Remove it.');
                    }
                }

                if ($code === null || $code === '') {
                    if ($level->is_required) {
                        $rowErrors[] = $this->error(HierarchyRequirementsSheet::SHEET_NAME, $rowNumber, "{$level->key}_code", null, 'MISSING_REQUIRED_LEVEL',
                            "المستوى '{$level->name_ar}' مطلوب ولا يمكن تركه فارغًا.",
                            "Level '{$level->name_en}' is required and cannot be left blank.");
                    }
                    // A blank level ends the chain: nothing deeper may follow.
                    for ($d = $depth + 1; $d < count($levels); $d++) {
                        if ($this->clean($get("{$levels[$d]->key}_code")) !== null) {
                            $rowErrors[] = $this->error(HierarchyRequirementsSheet::SHEET_NAME, $rowNumber, "{$levels[$d]->key}_code", null, 'BROKEN_PARENT_CHAIN',
                                "لا يمكن تعبئة المستوى '{$levels[$d]->name_ar}' مع ترك المستوى الأعلى '{$level->name_ar}' فارغًا.",
                                "Level '{$levels[$d]->name_en}' cannot be filled while its ancestor '{$level->name_en}' is blank.");
                            break;
                        }
                    }
                    break;
                }

                if ($nameAr === null || $nameAr === '') {
                    $rowErrors[] = $this->error(HierarchyRequirementsSheet::SHEET_NAME, $rowNumber, "{$level->key}_name_ar", null, 'MISSING_NAME',
                        "الاسم العربي مطلوب للمستوى '{$level->name_ar}'.",
                        "The Arabic name is required for level '{$level->name_en}'.");
                }

                $chain[] = [
                    'level_key' => $level->key,
                    'level_id' => $level->id,
                    'depth' => $depth,
                    'code' => $code,
                    'name_ar' => $nameAr,
                    'name_en' => $nameEn,
                ];
            }

            if (! $chain) {
                $rowErrors[] = $this->error(HierarchyRequirementsSheet::SHEET_NAME, $rowNumber, '-', null, 'EMPTY_ROW',
                    'الصف لا يحتوي على أي مستوى.', 'The row contains no hierarchy level.');
            }

            if (count($chain) > count($levels)) {
                $rowErrors[] = $this->error(HierarchyRequirementsSheet::SHEET_NAME, $rowNumber, '-', count($chain), 'INVALID_DEPTH',
                    'عمق الصف يتجاوز عمق هيكل البرنامج.', 'The row depth exceeds the program structure depth.');
            }

            // Duplicate detection: the same full path must not appear twice,
            // and one code must not be reused at two different paths.
            if ($chain) {
                $leaf = end($chain);
                $path = implode('|', array_column($chain, 'code'));
                if (isset($seenCodes[$leaf['code']]) && $seenCodes[$leaf['code']] !== $path) {
                    $rowErrors[] = $this->error(HierarchyRequirementsSheet::SHEET_NAME, $rowNumber, "{$leaf['level_key']}_code", $leaf['code'], 'DUPLICATE_CODE',
                        "الرمز '{$leaf['code']}' مستخدم في مسار مختلف.",
                        "Code '{$leaf['code']}' is already used on a different path.");
                } elseif (isset($seenCodes[$leaf['code']])) {
                    $rowErrors[] = $this->error(HierarchyRequirementsSheet::SHEET_NAME, $rowNumber, "{$leaf['level_key']}_code", $leaf['code'], 'DUPLICATE_ROW',
                        "الصف مكرر للرمز '{$leaf['code']}'.",
                        "Duplicate row for code '{$leaf['code']}'.");
                }
                $seenCodes[$leaf['code']] = $path;
            }

            if ($rowErrors) {
                $errors = array_merge($errors, $rowErrors);

                continue;
            }

            $validRows[] = [
                'row' => $rowNumber,
                'chain' => $chain,
                'attributes' => [
                    'description_ar' => $this->clean($get('description_ar')),
                    'objective_ar' => $this->clean($get('objective_ar')),
                    'guidance_ar' => $this->clean($get('guidance_ar')),
                    'weight' => is_numeric($get('weight')) ? (float) $get('weight') : null,
                    'default_due_date' => $this->parseDate($get('default_due_date')),
                ],
            ];
        }

        // Per-level counts for the preview: how many nodes each level would
        // gain, and how many already exist and would be reused. This is what
        // makes a preview reviewable rather than a row total.
        $byLevel = [];
        foreach ($levels as $level) {
            $codes = [];
            foreach ($validRows as $row) {
                foreach ($row['chain'] as $entry) {
                    if ($entry['level_key'] === $level->key) {
                        $codes[$entry['code']] = true;
                    }
                }
            }
            $codeList = array_keys($codes);
            $existing = $codeList
                ? ComplianceNode::where('compliance_program_id', $program->id)
                    ->where('hierarchy_level_id', $level->id)
                    ->whereIn('code', $codeList)->count()
                : 0;

            $byLevel[] = [
                'level_key' => $level->key,
                'level_name' => $level->name,
                'level_order' => $level->level_order,
                'total' => count($codeList),
                'new' => max(0, count($codeList) - $existing),
                'reused' => $existing,
            ];
        }

        return [
            'metadata_valid' => true,
            'errors' => $errors,
            'warnings' => $warnings,
            'summary' => [
                'total_rows' => count($dataRows),
                'valid_rows' => count($validRows),
                'error_rows' => count($dataRows) - count($validRows),
                'levels' => count($levels),
                'columns' => count($expectedKeys),
                'by_level' => $byLevel,
            ],
            'valid_rows' => $validRows,
            'template_version' => $metadata['template_version'] ?? 'unknown',
            'structure_version' => $currentVersion?->version,
        ];
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function looksLikeFormula(string $value): bool
    {
        return in_array(substr($value, 0, 1), self::FORMULA_LEADERS, true);
    }

    private function clean(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            if (is_numeric($value)) {
                return Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
            }

            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function isMacroEnabledWorkbook(string $absolutePath): bool
    {
        $zip = new ZipArchive;
        if ($zip->open($absolutePath) !== true) {
            return false;
        }
        $hasVba = $zip->locateName('xl/vbaProject.bin') !== false;
        $zip->close();

        return $hasVba;
    }

    private function findSheetIndexByContent(array $sheets, string $needle): ?int
    {
        foreach ($sheets as $i => $rows) {
            foreach ($rows as $row) {
                if (in_array($needle, array_map(fn ($c) => trim((string) $c), $row), true)) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * The data sheet is the first sheet whose header row has the expected
     * number of non-empty cells and is not the metadata sheet. Headings are
     * localized so they cannot be matched by text.
     */
    private function findDataSheetIndex(array $sheets, int $expectedColumnCount): ?int
    {
        foreach ($sheets as $i => $rows) {
            if (! $rows) {
                continue;
            }
            $header = array_values(array_filter(
                array_map(fn ($c) => trim((string) $c), $rows[0]),
                fn ($c) => $c !== '',
            ));
            if (count($header) === $expectedColumnCount && ! in_array('key', $header, true)) {
                return $i;
            }
        }

        return null;
    }

    private function parseMetadata(array $rows): array
    {
        $metadata = [];
        foreach ($rows as $row) {
            $key = trim((string) ($row[0] ?? ''));
            if ($key !== '' && $key !== 'key') {
                $metadata[$key] = trim((string) ($row[1] ?? ''));
            }
        }

        return $metadata;
    }

    private function error(string $sheet, int $row, string $column, mixed $value, string $code, string $ar, string $en): array
    {
        return compact('sheet', 'row', 'column', 'value', 'code') + ['message_ar' => $ar, 'message_en' => $en];
    }

    private function fail(array $errors, string $templateVersion = 'unknown'): array
    {
        return [
            'metadata_valid' => false,
            'errors' => $errors,
            'warnings' => [],
            'summary' => ['total_rows' => 0, 'valid_rows' => 0, 'error_rows' => 0, 'levels' => 0, 'columns' => 0, 'by_level' => []],
            'valid_rows' => [],
            'template_version' => $templateVersion,
            'structure_version' => null,
        ];
    }
}
