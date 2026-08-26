<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * DocumentVersion stores individual file uploads.
 * Files are never overwritten; each upload creates a new version.
 */
class DocumentVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id', 'version_number', 'file_path',
        'original_filename', 'file_size', 'file_type',
        'file_hash', 'change_reason', 'uploaded_by', 'uploaded_at',
    ];

    protected function casts(): array
    {
        return ['uploaded_at' => 'datetime'];
    }

    // ─── Relationships ───────────────────────────────────────────────────────

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /** Returns human-readable file size. */
    public function getFileSizeHumanAttribute(): string
    {
        $bytes = $this->file_size;
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / 1048576, 1).' MB';
    }
}
