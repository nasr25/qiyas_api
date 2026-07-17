<?php

namespace App\Http\Controllers\Api\Workflow;

use App\Models\ComplianceProgram;
use Illuminate\Http\Request;

class DepartmentManagerReviewController extends ReviewQueueController
{
    protected function stage(): string
    {
        return 'department_manager';
    }

    protected function ability(): string
    {
        return 'reviewAsDepartmentManager';
    }

    protected function roleLabel(): string
    {
        return 'department-manager';
    }

    protected function scopeToReviewer($query, Request $request, ComplianceProgram $program)
    {
        $departmentId = $request->user()->managedDepartmentId($program);
        if (! $request->user()->isPlatformSuperAdmin()) {
            $query->where('department_id', $departmentId);
        }

        return $query;
    }
}
