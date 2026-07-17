<?php

namespace App\Http\Controllers\Api\Workflow;

use App\Models\ComplianceProgram;
use Illuminate\Http\Request;

class ProgramManagerReviewController extends ReviewQueueController
{
    protected function stage(): string
    {
        return 'program_manager';
    }

    protected function ability(): string
    {
        return 'reviewAsProgramManager';
    }

    protected function roleLabel(): string
    {
        return 'program-manager';
    }

    protected function scopeToReviewer($query, Request $request, ComplianceProgram $program)
    {
        return $query;
    }
}
