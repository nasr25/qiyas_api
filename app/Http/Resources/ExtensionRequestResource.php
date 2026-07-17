<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExtensionRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_id' => $this->document_id,
            'requested_date' => $this->requested_date?->toDateString(),
            'reason' => $this->reason,
            'status' => $this->status,
            'reviewer_notes' => $this->reviewer_notes,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'document' => new DocumentResource($this->whenLoaded('document')),
            'requester' => $this->whenLoaded('requester', fn () => [
                'id' => $this->requester->id,
                'name' => $this->requester->name,
            ]),
            'reviewer' => $this->whenLoaded('reviewer', fn () => [
                'id' => $this->reviewer->id,
                'name' => $this->reviewer->name,
            ]),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
