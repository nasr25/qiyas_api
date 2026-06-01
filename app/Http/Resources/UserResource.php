<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UserResource formats user data for API responses.
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                    => $this->id,
            'name'                  => $this->name,
            'username'              => $this->username,
            'email'                 => $this->email,
            'auth_type'             => $this->auth_type,
            'department_id'         => $this->department_id,
            'department'            => $this->whenLoaded('department', fn() => [
                'id'      => $this->department->id,
                'name_ar' => $this->department->name_ar,
                'name_en' => $this->department->name_en,
            ]),
            'avatar_url'            => $this->avatar_url,
            'is_active'             => $this->is_active,
            'must_change_password'  => $this->must_change_password,
            'locale'                => $this->locale,
            'roles'                 => $this->getRoleNames(),
            'permissions'           => $this->getAllPermissions()->pluck('name'),
            'last_login_at'         => $this->last_login_at?->toIso8601String(),
            'created_at'            => $this->created_at->toIso8601String(),
        ];
    }
}
