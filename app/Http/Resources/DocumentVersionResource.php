<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_id' => $this->document_id,
            'version_number' => $this->version_number,
            'original_filename' => $this->original_filename,
            'file_size' => $this->file_size,
            'file_size_human' => $this->file_size_human,
            'file_type' => $this->file_type,
            'file_hash' => $this->file_hash,
            'change_reason' => $this->change_reason,
            'uploaded_at' => $this->uploaded_at?->toIso8601String(),
            'uploader' => $this->whenLoaded('uploader', fn () => [
                'id' => $this->uploader->id,
                'name' => $this->uploader->name,
            ]),
        ];
    }
}
