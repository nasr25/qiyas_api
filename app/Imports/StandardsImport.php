<?php

namespace App\Imports;

use App\Models\AssessmentCycle;
use App\Models\Department;
use App\Models\Standard;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Bulk-imports standards into a cycle from an uploaded spreadsheet.
 * Idempotent on (cycle_id, standard_number): existing rows are updated.
 * Departments are matched by Arabic name, English name, or numeric id.
 */
class StandardsImport implements ToCollection, WithHeadingRow
{
    public int $created = 0;

    public int $updated = 0;

    public array $errors = [];   // [{ row, message }]

    private Collection $departmentLookup;

    public function __construct(
        private readonly AssessmentCycle $cycle,
        private readonly int $userId,
    ) {
        // name_ar|name_en|id (lowercased & trimmed) => department id
        $this->departmentLookup = Department::all()->flatMap(fn ($d) => [
            mb_strtolower(trim($d->name_ar)) => $d->id,
            mb_strtolower(trim($d->name_en)) => $d->id,
            (string) $d->id => $d->id,
        ]);
    }

    public function collection(Collection $rows): void
    {
        foreach ($rows as $i => $row) {
            $rowNumber = $i + 2; // +1 for heading row, +1 for 1-based display
            $data = [
                'standard_number' => $this->str($row['standard_number'] ?? null),
                'name_ar' => $this->str($row['name_ar'] ?? null),
                'name_en' => $this->str($row['name_en'] ?? null),
                'description' => $this->str($row['description'] ?? null) ?: null,
                'version' => $this->str($row['version'] ?? null) ?: null,
                'weight' => $this->num($row['weight'] ?? null),
                'due_date' => $this->date($row['due_date'] ?? null),
            ];

            // Skip fully blank rows silently.
            if ($data['standard_number'] === '' && $data['name_ar'] === '' && $data['name_en'] === '') {
                continue;
            }

            $validator = Validator::make($data, [
                'standard_number' => ['required', 'string', 'max:50'],
                'name_ar' => ['required', 'string', 'max:500'],
                'name_en' => ['required', 'string', 'max:500'],
                'version' => ['nullable', 'string', 'max:20'],
                'weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'due_date' => ['nullable', 'date'],
            ]);

            if ($validator->fails()) {
                $this->errors[] = ['row' => $rowNumber, 'message' => implode(' ', $validator->errors()->all())];

                continue;
            }

            $existing = $this->cycle->standards()->where('standard_number', $data['standard_number'])->exists();

            $standard = $this->cycle->standards()->updateOrCreate(
                ['standard_number' => $data['standard_number']],
                $data,
            );

            $existing ? $this->updated++ : $this->created++;

            $this->syncDepartments($standard, $row['departments'] ?? null, $rowNumber);
        }
    }

    /** Matches the departments cell (comma/semicolon separated) and assigns them. */
    private function syncDepartments(Standard $standard, $cell, int $rowNumber): void
    {
        $cell = $this->str($cell);
        if ($cell === '') {
            return;
        }

        $ids = [];
        $unknown = [];
        foreach (preg_split('/[,;،]/u', $cell) as $token) {
            $key = mb_strtolower(trim($token));
            if ($key === '') {
                continue;
            }
            if ($this->departmentLookup->has($key)) {
                $ids[] = $this->departmentLookup->get($key);
            } else {
                $unknown[] = trim($token);
            }
        }

        if ($unknown) {
            $this->errors[] = ['row' => $rowNumber, 'message' => 'Unknown department(s): '.implode(', ', $unknown)];
        }

        if ($ids) {
            $pivot = collect(array_unique($ids))->mapWithKeys(fn ($id) => [
                $id => ['assigned_at' => now(), 'assigned_by' => $this->userId],
            ])->toArray();
            $standard->departments()->syncWithoutDetaching($pivot);
        }
    }

    private function str($v): string
    {
        return trim((string) ($v ?? ''));
    }

    private function num($v): ?float
    {
        $v = $this->str($v);

        return $v === '' ? null : (is_numeric($v) ? (float) $v : null);
    }

    /** Accepts Y-m-d strings or Excel serial date numbers. */
    private function date($v): ?string
    {
        if ($v === null || $this->str($v) === '') {
            return null;
        }
        try {
            if (is_numeric($v)) {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $v))->toDateString();
            }

            return Carbon::parse($this->str($v))->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
