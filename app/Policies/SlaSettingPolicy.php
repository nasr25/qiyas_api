<?php

namespace App\Policies;

use App\Models\SlaSetting;
use App\Models\User;

class SlaSettingPolicy
{
    public function manage(User $user, SlaSetting $setting): bool
    {
        return $user->isPlatformSuperAdmin()
            || $user->hasProgramRole($setting->program, 'program-manager');
    }
}
