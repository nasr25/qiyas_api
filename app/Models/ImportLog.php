<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportLog extends Model
{
    protected $fillable = [
        'compliance_program_id', 'program_cycle_id', 'imported_by', 'original_file_name',
        'stored_file_name', 'file_hash', 'template_version', 'mode', 'status',
        'total_rows', 'valid_rows', 'invalid_rows', 'created_records', 'updated_records',
        'warning_count', 'error_count', 'validation_report_path', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function program()
    {
        return $this->belongsTo(ComplianceProgram::class, 'compliance_program_id');
    }

    public function importer()
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
