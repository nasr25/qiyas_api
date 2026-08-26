<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use Tymon\JWTAuth\Contracts\JWTSubject;

/**
 * User model supporting both LDAP and local authentication.
 */
class User extends Authenticatable implements JWTSubject
{
    use HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'name', 'username', 'email', 'password',
        'auth_type', 'department_id', 'avatar',
        'is_active', 'must_change_password',
        'last_login_at', 'last_login_ip', 'locale',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [
            'name' => $this->name,
            'username' => $this->username,
            'auth_type' => $this->auth_type,
            'locale' => $this->locale,
        ];
    }

    // ─── Relationships ───────────────────────────────────────────────────────

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function documents()
    {
        return $this->hasMany(Document::class, 'submitted_by');
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }

    public function extensionRequests()
    {
        return $this->hasMany(ExtensionRequest::class, 'requested_by');
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    public function programRoles()
    {
        return $this->hasMany(ProgramUserRole::class);
    }

    public function requirementAssignments()
    {
        return $this->hasMany(RequirementAssignment::class, 'employee_id');
    }

    public function evidenceSubmissions()
    {
        return $this->hasMany(EvidenceSubmission::class, 'submitted_by');
    }

    public function workflowDecisions()
    {
        return $this->hasMany(WorkflowDecision::class, 'reviewer_id');
    }

    // ─── Program access helpers ────────────────────────────────────────────
    // Platform-level roles (super-admin, executive) bypass program_user_roles
    // entirely: super-admin has full access to every program, executive has
    // implicit read-only access to every active program. Everyone else needs
    // an active program_user_roles row for that specific program.

    public function isPlatformSuperAdmin(): bool
    {
        return $this->hasRole('super-admin');
    }

    public function isPlatformExecutiveViewer(): bool
    {
        return $this->hasRole('executive');
    }

    public function hasProgramAccess(ComplianceProgram $program): bool
    {
        if ($this->isPlatformSuperAdmin()) {
            return true;
        }

        if ($this->isPlatformExecutiveViewer()) {
            return $program->is_active && $program->status === 'active';
        }

        return $this->programRoles()
            ->active()
            ->where('compliance_program_id', $program->id)
            ->exists();
    }

    /** The user's role_key(s) inside a given program (a user may hold more than one, e.g. department-manager + employee is not expected but not prevented). */
    public function programRoleKeys(ComplianceProgram $program): array
    {
        return $this->programRoles()
            ->active()
            ->where('compliance_program_id', $program->id)
            ->pluck('role_key')
            ->all();
    }

    public function hasProgramRole(ComplianceProgram $program, string $roleKey): bool
    {
        if ($this->isPlatformSuperAdmin()) {
            return true;
        }

        return $this->programRoles()
            ->active()
            ->where('compliance_program_id', $program->id)
            ->where('role_key', $roleKey)
            ->exists();
    }

    /** The department this user manages inside a program, if they hold the department-manager role there. */
    public function managedDepartmentId(ComplianceProgram $program): ?int
    {
        return $this->programRoles()
            ->active()
            ->where('compliance_program_id', $program->id)
            ->where('role_key', ProgramUserRole::ROLE_DEPARTMENT_MANAGER)
            ->value('department_id');
    }

    /** The department this user belongs to as an employee inside a program (falls back to the platform department_id column). */
    public function employeeDepartmentId(ComplianceProgram $program): ?int
    {
        return $this->programRoles()
            ->active()
            ->where('compliance_program_id', $program->id)
            ->where('role_key', ProgramUserRole::ROLE_EMPLOYEE)
            ->value('department_id') ?? $this->department_id;
    }

    // ─── Accessors ───────────────────────────────────────────────────────────

    public function getAvatarUrlAttribute(): string
    {
        if ($this->avatar) {
            return asset('storage/'.$this->avatar);
        }

        return 'https://ui-avatars.com/api/?name='.urlencode($this->name).'&background=1e3a5f&color=fff&size=128';
    }
}
