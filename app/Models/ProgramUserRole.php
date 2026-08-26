<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Program-scoped role assignment: one row grants $user a role_key
 * (program-manager|auditor|department-manager|employee) inside one
 * ComplianceProgram, optionally restricted to one Department.
 *
 * This is deliberately separate from spatie/laravel-permission's global
 * roles table — platform-level roles (super-admin, executive) remain
 * spatie roles; this table exists purely for the new program dimension.
 * See docs/roles-and-scopes.md.
 */
class ProgramUserRole extends Model
{
    use HasFactory;

    public const ROLE_PROGRAM_MANAGER = 'program-manager';

    public const ROLE_AUDITOR = 'auditor';

    public const ROLE_DEPARTMENT_MANAGER = 'department-manager';

    public const ROLE_EMPLOYEE = 'employee';

    protected $fillable = [
        'user_id', 'compliance_program_id', 'role_key', 'department_id',
        'is_active', 'assigned_by', 'assigned_at', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'assigned_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────────────────

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function program()
    {
        return $this->belongsTo(ComplianceProgram::class, 'compliance_program_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    // ─── Scopes ──────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->whereNull('revoked_at');
    }
}
