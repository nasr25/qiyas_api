<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssessmentCycleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'year' => $this->year,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'status' => $this->status,
            'final_score' => $this->final_score,
            'closing_notes' => $this->closing_notes,
            'activated_at' => $this->activated_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'is_read_only' => $this->isReadOnly(),
            'standards_count' => $this->whenCounted('standards'),
            'creator' => $this->whenLoaded('creator', fn () => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
