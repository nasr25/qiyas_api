<?php

namespace Database\Seeders;

use App\Models\ComplianceProgram;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * The QIYAS compliance program row itself is created by a schema migration
 * (2026_07_17_000001_create_compliance_programs_table), not a seeder, so it
 * exists on every environment even before seeders run. This seeder only
 * stamps created_by/updated_by once a super-admin user is available, for a
 * meaningful audit trail. Idempotent.
 */
class ComplianceProgramsAttributionSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::whereHas('roles', fn ($q) => $q->where('name', 'super-admin'))->first();
        if (! $admin) {
            return;
        }

        ComplianceProgram::where('code', 'QIYAS')
            ->whereNull('created_by')
            ->update(['created_by' => $admin->id, 'updated_by' => $admin->id]);
    }
}
