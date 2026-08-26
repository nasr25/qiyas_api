<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single versioned branding asset (logo, favicon, ...). See
 * database/migrations/2026_07_23_000001_create_branding_assets_table.php
 * for why versioning replaces the previous overwrite-in-place upload.
 */
class BrandingAsset extends Model
{
    protected $fillable = [
        'asset_type', 'version', 'status', 'original_filename', 'storage_path',
        'mime_type', 'file_size', 'width', 'height', 'file_hash',
        'uploaded_by', 'uploaded_at', 'activated_at', 'previous_version_id',
    ];

    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
            'activated_at' => 'datetime',
            'file_size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function previousVersion()
    {
        return $this->belongsTo(self::class, 'previous_version_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('asset_type', $type);
    }
}
