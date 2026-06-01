<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepartmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name_ar'     => $this->name_ar,
            'name_en'     => $this->name_en,
            'name'        => $this->name,
            'description' => $this->description,
            'is_active'   => $this->is_active,
            'users_count' => $this->whenCounted('users'),
            'created_at'  => $this->created_at->toIso8601String(),
        ];
    }
}
