<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StandardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cycle_id' => $this->cycle_id,
            'standard_number' => $this->standard_number,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'name' => $this->name,
            'description' => $this->description,
            'perspective' => $this->perspective,
            'axis' => $this->axis,
            'application_requirements' => $this->application_requirements,
            'evidence_documents' => $this->evidence_documents,
            'scope' => $this->scope,
            'related_references' => $this->related_references,
            'standard_status' => $this->status,
            'version' => $this->version,
            'weight' => $this->weight,
            'due_date' => $this->due_date?->toDateString(),
            'is_active' => $this->is_active,
            'departments' => DepartmentResource::collection($this->whenLoaded('departments')),
            'evidence_requirements' => EvidenceRequirementResource::collection(
                $this->whenLoaded('evidenceRequirements')
            ),
            'requirements_count' => $this->whenCounted('evidenceRequirements'),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
