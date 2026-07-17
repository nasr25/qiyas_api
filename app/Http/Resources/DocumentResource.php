<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'requirement_id' => $this->requirement_id,
            'department_id' => $this->department_id,
            'cycle_id' => $this->cycle_id,
            'title' => $this->title,
            'status' => $this->status,
            'current_version' => $this->current_version,
            'rejection_reason' => $this->rejection_reason,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'requirement' => new EvidenceRequirementResource($this->whenLoaded('requirement')),
            'department' => new DepartmentResource($this->whenLoaded('department')),
            'submitter' => $this->whenLoaded('submitter', fn () => [
                'id' => $this->submitter->id,
                'name' => $this->submitter->name,
            ]),
            'reviewer' => $this->whenLoaded('reviewer', fn () => [
                'id' => $this->reviewer->id,
                'name' => $this->reviewer->name,
            ]),
            'versions' => DocumentVersionResource::collection($this->whenLoaded('versions')),
            'current_file' => new DocumentVersionResource($this->whenLoaded('currentVersionFile')),
            'versions_count' => $this->whenCounted('versions'),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
