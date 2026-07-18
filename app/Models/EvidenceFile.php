<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EvidenceFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'evidence_submission_id', 'original_name', 'stored_name', 'storage_path',
        'mime_type', 'file_size', 'file_hash', 'classification_metadata', 'uploaded_by', 'uploaded_at', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
            'is_active' => 'boolean',
            'file_size' => 'integer',
            'classification_metadata' => 'array',
        ];
    }

    public function submission()
    {
        return $this->belongsTo(EvidenceSubmission::class, 'evidence_submission_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getFileSizeHumanAttribute(): string
    {
        $bytes = $this->file_size;
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1).' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return $bytes.' B';
    }
}
