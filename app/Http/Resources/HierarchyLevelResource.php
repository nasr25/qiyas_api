<?php

namespace App\Http\Resources;

use App\Models\HierarchyLevelDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Hierarchy-neutral representation of one level definition.
 *
 * Both raw bilingual fields AND a resolved `name`/`plural_name` are sent:
 * the resolved pair is what every screen renders (fixing audit finding H5,
 * where the frontend displayed `label_ar` unconditionally and English users
 * saw Arabic), while the raw pair is what the Structure Settings editor
 * binds to.
 */
class HierarchyLevelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $flags = [];
        foreach (HierarchyLevelDefinition::BEHAVIOUR_FLAGS as $flag) {
            $flags[$flag] = (bool) $this->{$flag};
        }

        return [
            'id' => $this->id,
            'key' => $this->key,

            // Resolved for display…
            'name' => $this->name,
            'plural_name' => $this->plural_name,

            // …raw for editing.
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'plural_name_ar' => $this->plural_name_ar,
            'plural_name_en' => $this->plural_name_en,

            'level_order' => $this->level_order,
            'parent_level_id' => $this->parent_level_id,
            'icon' => $this->icon,
            ...$flags,
        ];
    }
}
