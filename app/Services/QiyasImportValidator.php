<?php

namespace App\Services;

use App\Exports\Qiyas\MetadataSheet;
use App\Exports\Qiyas\RequirementsSheet;
use App\Models\AssessmentCycle;
use App\Models\ComplianceProgram;
use App\Models\Standard;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * Validates an uploaded workbook against the official Qiyas import
 * template — never trusts visible column headers alone; the hidden
 * `_metadata` sheet is checked first. Never writes to the database — see
 * QiyasImportService for the transactional confirm step. See
 * docs/qiyas-xlsx-import.md for the full rule list and error codes.
 */
class QiyasImportValidator
{
    private const MAX_ROWS = 5000;

    /**
     * @return array{
     *   metadata_valid: bool, errors: array, warnings: array,
     *   summary: array, valid_rows: array, template_version: string,
     * }
     */
    public function validate(string $absolutePath, ComplianceProgram $program, AssessmentCycle $cycle): array
    {
        $errors = [];
        $warnings = [];

        if ($this->isMacroEnabledWorkbook($absolutePath)) {
            return $this->fail([['sheet' => '-', 'row' => 0, 'column' => '-', 'value' => null, 'code' => 'MACRO_ENABLED_REJECTED', 'message_ar' => 'الملفات التي تحتوي على وحدات ماكرو (VBA) مرفوضة لأسباب أمنية، حتى لو كان امتدادها xlsx. الرجاء استخدام القالب الرسمي دون تعديل نوعه.', 'message_en' => 'Workbooks containing VBA macros are rejected for security reasons, even if renamed to .xlsx. Please use the official template unmodified.']]);
        }

        try {
            $sheets = Excel::toArray(null, $absolutePath);
        } catch (\Throwable $e) {
            return $this->fail([['sheet' => '-', 'row' => 0, 'column' => '-', 'value' => null, 'code' => 'UNREADABLE_WORKBOOK', 'message_ar' => 'تعذّرت قراءة الملف. تأكد أنه بصيغة xlsx صالحة.', 'message_en' => 'The workbook could not be read. Ensure it is a valid .xlsx file.']]);
        }

        $sheetNames = array_keys($sheets);
        // maatwebsite/excel indexes toArray() results numerically; recover titles via a second pass when available.
        $metadataIndex = $this->findSheetIndexByContent($sheets, 'template_version');
        if ($metadataIndex === null) {
            return $this->fail([['sheet' => '_metadata', 'row' => 0, 'column' => '-', 'value' => null, 'code' => 'MISSING_METADATA', 'message_ar' => 'الملف لا يحتوي على ورقة البيانات الوصفية المخفية. يجب استخدام القالب الرسمي المُصدَّر من المنصة.', 'message_en' => 'The workbook is missing the hidden metadata sheet. You must use the official platform-generated template.']]);
        }

        $metadata = $this->parseMetadata($sheets[$metadataIndex]);

        if (($metadata['program_code'] ?? null) !== $program->code) {
            $errors[] = ['sheet' => '_metadata', 'row' => 0, 'column' => 'program_code', 'value' => $metadata['program_code'] ?? null, 'code' => 'WRONG_PROGRAM', 'message_ar' => 'هذا القالب مخصص لبرنامج آخر.', 'message_en' => 'This template was generated for a different program.'];
        }
        if (($metadata['template_version'] ?? null) !== MetadataSheet::TEMPLATE_VERSION) {
            $errors[] = ['sheet' => '_metadata', 'row' => 0, 'column' => 'template_version', 'value' => $metadata['template_version'] ?? null, 'code' => 'UNSUPPORTED_VERSION', 'message_ar' => 'إصدار القالب غير مدعوم. الرجاء تنزيل القالب الحالي.', 'message_en' => 'Unsupported template version. Please download the current template.'];
        }

        $expectedColumns = ! empty($metadata['column_identifiers'])
            ? explode(',', $metadata['column_identifiers'])
            : RequirementsSheet::COLUMNS;

        if ($errors) {
            return $this->fail($errors, $metadata['template_version'] ?? 'unknown');
        }

        $reqIndex = $this->findSheetIndexByHeadings($sheets, $expectedColumns);
        if ($reqIndex === null) {
            return $this->fail([['sheet' => 'Requirements', 'row' => 1, 'column' => '-', 'value' => null, 'code' => 'MISSING_REQUIREMENTS_SHEET', 'message_ar' => 'ورقة "Requirements" غير موجودة أو أعمدتها لا تطابق القالب الرسمي.', 'message_en' => 'The "Requirements" sheet is missing or its columns do not match the official template.']], $metadata['template_version']);
        }

        $rows = $sheets[$reqIndex];
        $header = array_map('trim', $rows[0] ?? []);
        $columnMap = array_flip($header); // column_identifier => 0-based index

        $dataRows = array_slice($rows, 1);
        if (count($dataRows) > self::MAX_ROWS) {
            $errors[] = ['sheet' => 'Requirements', 'row' => 0, 'column' => '-', 'value' => count($dataRows), 'code' => 'TOO_MANY_ROWS', 'message_ar' => 'عدد الصفوف يتجاوز الحد الأقصى المسموح ('.self::MAX_ROWS.').', 'message_en' => 'Row count exceeds the maximum allowed ('.self::MAX_ROWS.').'];

            return $this->fail($errors, $metadata['template_version']);
        }

        $seenCodes = [];
        $existingCodes = Standard::where('cycle_id', $cycle->id)->pluck('standard_number')->flip();
        $validRows = [];

        foreach ($dataRows as $i => $row) {
            $rowNumber = $i + 2; // header + 1-based
            $get = fn (string $key) => isset($columnMap[$key], $row[$columnMap[$key]]) ? trim((string) $row[$columnMap[$key]]) : '';

            $standardNumber = $get('standard_number');
            $nameAr = $get('name_ar');

            // Fully empty rows are skipped silently, not reported as errors.
            if ($standardNumber === '' && $nameAr === '' && $get('name_en') === '') {
                continue;
            }

            $rowErrors = [];

            if ($standardNumber === '') {
                $rowErrors[] = $this->err('Requirements', $rowNumber, 'standard_number', $standardNumber, 'REQUIRED', 'رمز المعيار مطلوب.', 'Standard code is required.');
            } elseif (mb_strlen($standardNumber) > 50) {
                $rowErrors[] = $this->err('Requirements', $rowNumber, 'standard_number', $standardNumber, 'TOO_LONG', 'رمز المعيار طويل جداً.', 'Standard code is too long.');
            } elseif (isset($seenCodes[$standardNumber])) {
                $rowErrors[] = $this->err('Requirements', $rowNumber, 'standard_number', $standardNumber, 'DUPLICATE_IN_FILE', 'رمز المعيار مكرر داخل الملف.', 'Duplicate standard code within this file.');
            } elseif ($existingCodes->has($standardNumber)) {
                $rowErrors[] = $this->err('Requirements', $rowNumber, 'standard_number', $standardNumber, 'DUPLICATE_IN_CYCLE', 'رمز المعيار مستخدم بالفعل في هذه الدورة.', 'Standard code already exists in this cycle.');
            }
            $seenCodes[$standardNumber] = true;

            if ($nameAr === '') {
                $rowErrors[] = $this->err('Requirements', $rowNumber, 'name_ar', $nameAr, 'REQUIRED', 'اسم المعيار بالعربية مطلوب.', 'Arabic standard name is required.');
            } elseif (mb_strlen($nameAr) > 500) {
                $rowErrors[] = $this->err('Requirements', $rowNumber, 'name_ar', $nameAr, 'TOO_LONG', 'اسم المعيار طويل جداً.', 'Standard name is too long.');
            }

            $weight = $get('weight');
            if ($weight !== '') {
                if (! is_numeric($weight) || $weight < 0 || $weight > 100) {
                    $rowErrors[] = $this->err('Requirements', $rowNumber, 'weight', $weight, 'INVALID_WEIGHT', 'الوزن يجب أن يكون رقماً بين 0 و100.', 'Weight must be a number between 0 and 100.');
                }
            }

            $dueDate = $get('due_date');
            $parsedDate = null;
            if ($dueDate !== '') {
                $parsedDate = $this->parseDate($dueDate);
                if (! $parsedDate) {
                    $rowErrors[] = $this->err('Requirements', $rowNumber, 'due_date', $dueDate, 'INVALID_DATE', 'صيغة التاريخ غير صحيحة (YYYY-MM-DD).', 'Invalid date format (YYYY-MM-DD).');
                }
            }

            if ($rowErrors) {
                $errors = array_merge($errors, $rowErrors);

                continue;
            }

            $validRows[] = [
                'row_number' => $rowNumber,
                'perspective' => $get('perspective') ?: null,
                'axis' => $get('axis') ?: null,
                'standard_number' => $standardNumber,
                'name_ar' => $nameAr,
                'name_en' => $get('name_en') ?: null,
                'description' => $get('description') ?: null,
                'application_requirements' => $get('application_requirements') ?: null,
                'evidence_documents' => $get('evidence_documents') ?: null,
                'weight' => $weight !== '' ? (float) $weight : null,
                'due_date' => $parsedDate,
                'is_new' => ! $existingCodes->has($standardNumber),
            ];
        }

        $newCount = collect($validRows)->where('is_new', true)->count();
        $newPerspectives = collect($validRows)->pluck('perspective')->filter()->unique()->values();
        $newAxes = collect($validRows)->pluck('axis')->filter()->unique()->values();

        return [
            'metadata_valid' => true,
            'template_version' => $metadata['template_version'],
            'errors' => $errors,
            'warnings' => $warnings,
            'valid_rows' => $validRows,
            'summary' => [
                'total_rows' => count($dataRows),
                'valid_rows' => count($validRows),
                'invalid_rows' => count($errors) > 0 ? count(array_unique(array_column($errors, 'row'))) : 0,
                'new_standards' => $newCount,
                'existing_standards' => count($validRows) - $newCount,
                'new_perspectives' => $newPerspectives,
                'new_axes' => $newAxes,
                'warning_count' => count($warnings),
                'error_count' => count($errors),
            ],
        ];
    }

    /**
     * A .xlsm file renamed to .xlsx is still a valid OOXML/ZIP container and
     * would otherwise pass both the filename-extension check in
     * QiyasImportController and PhpSpreadsheet's own reader — the definitive
     * signal that a workbook is macro-enabled is the presence of a VBA
     * project part inside the package, so it is checked directly here
     * rather than trusted from the client-supplied filename. This runs on
     * both preview and confirm, since both call validate() against the same
     * stored file.
     */
    private function isMacroEnabledWorkbook(string $absolutePath): bool
    {
        $zip = new \ZipArchive;
        if ($zip->open($absolutePath) !== true) {
            // Not a readable zip container at all — let the normal
            // "unreadable workbook" path below report that clearly.
            return false;
        }

        $hasVbaProject = $zip->locateName('xl/vbaProject.bin') !== false;
        $zip->close();

        return $hasVbaProject;
    }

    private function fail(array $errors, string $templateVersion = 'unknown'): array
    {
        return [
            'metadata_valid' => false,
            'template_version' => $templateVersion,
            'errors' => $errors,
            'warnings' => [],
            'valid_rows' => [],
            'summary' => ['total_rows' => 0, 'valid_rows' => 0, 'invalid_rows' => 0, 'new_standards' => 0, 'existing_standards' => 0, 'new_perspectives' => [], 'new_axes' => [], 'warning_count' => 0, 'error_count' => count($errors)],
        ];
    }

    private function err(string $sheet, int $row, string $column, mixed $value, string $code, string $ar, string $en): array
    {
        return ['sheet' => $sheet, 'row' => $row, 'column' => $column, 'value' => $value, 'code' => $code, 'message_ar' => $ar, 'message_en' => $en];
    }

    private function findSheetIndexByContent(array $sheets, string $needle): ?int
    {
        foreach ($sheets as $index => $rows) {
            foreach (array_slice($rows, 0, 3) as $row) {
                if (in_array($needle, array_map(fn ($c) => is_string($c) ? trim($c) : $c, $row), true)) {
                    return $index;
                }
            }
        }

        return null;
    }

    private function findSheetIndexByHeadings(array $sheets, array $expectedColumns): ?int
    {
        foreach ($sheets as $index => $rows) {
            $header = array_map(fn ($c) => is_string($c) ? trim($c) : $c, $rows[0] ?? []);
            if (count(array_intersect($expectedColumns, $header)) === count($expectedColumns)) {
                return $index;
            }
        }

        return null;
    }

    private function parseMetadata(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (isset($row[0], $row[1]) && $row[0] !== 'key') {
                $out[$row[0]] = $row[1];
            }
        }

        return $out;
    }

    private function parseDate(string $value): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            try {
                return Carbon::createFromFormat('Y-m-d', $value)->toDateString();
            } catch (\Throwable) {
                return null;
            }
        }
        if (is_numeric($value)) {
            try {
                return Carbon::instance(Date::excelToDateTimeObject((float) $value))->toDateString();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
