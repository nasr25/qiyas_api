<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EvidenceRequirementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'standard_id'  => $this->standard_id,
            'title_ar'     => $this->title_ar,
            'title_en'     => $this->title_en,
            'title'        => $this->title,
            'description'  => $this->description,
            'is_mandatory' => $this->is_mandatory,
            'sort_order'   => $this->sort_order,
            'created_at'   => $this->created_at->toIso8601String(),
        ];
    }
}
