<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ComplianceProgramResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $currentCycle = $this->whenLoaded('currentCycle', fn () => $this->currentCycle ? [
            'id' => $this->currentCycle->id,
            'name' => $this->currentCycle->name,
            'year' => $this->currentCycle->year,
            'status' => $this->currentCycle->status,
        ] : null);

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'name' => $this->name,
            'description_ar' => $this->description_ar,
            'description_en' => $this->description_en,
            'description' => $this->description,
            'logo' => $this->logo,
            'icon' => $this->icon,
            'status' => $this->status,
            'is_active' => $this->is_active,
            'primary_color' => $this->primary_color,
            'secondary_color' => $this->secondary_color,
            'terminology' => $this->terminology,
            'sort_order' => $this->sort_order,
            'current_cycle' => $currentCycle,
            'role_keys' => $this->when(
                $request->user(),
                fn () => $request->user()->programRoleKeys($this->resource)
            ),
            'is_platform_role_access' => $this->when(
                $request->user(),
                fn () => $request->user()->isPlatformSuperAdmin() || $request->user()->isPlatformExecutiveViewer()
            ),
            'summary' => $this->when(isset($this->summary), fn () => $this->summary),
        ];
    }
}
