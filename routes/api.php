<?php

use App\Http\Controllers\Api\Admin\AuditLogController;
use App\Http\Controllers\Api\Admin\BrandingController;
use App\Http\Controllers\Api\Admin\EmailLogController;
use App\Http\Controllers\Api\Admin\EmailTemplateController;
use App\Http\Controllers\Api\Admin\HealthController;
use App\Http\Controllers\Api\Admin\SettingController;
use App\Http\Controllers\Api\Admin\SmtpSettingsController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Cycles\AssessmentCycleController;
use App\Http\Controllers\Api\Departments\DepartmentController;
use App\Http\Controllers\Api\ExecutiveDashboardController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\Programs\ComplianceHierarchyController;
use App\Http\Controllers\Api\Programs\ComplianceProgramController;
use App\Http\Controllers\Api\Programs\HierarchyDashboardController;
use App\Http\Controllers\Api\Programs\HierarchyImportController;
use App\Http\Controllers\Api\Programs\HierarchyReportController;
use App\Http\Controllers\Api\Programs\ProgramCycleController;
use App\Http\Controllers\Api\Programs\ProgramDashboardController;
use App\Http\Controllers\Api\Programs\ProgramReportController;
use App\Http\Controllers\Api\Programs\ProgramRequirementController;
use App\Http\Controllers\Api\Programs\ProgramStructureController;
use App\Http\Controllers\Api\Reports\ExportController;
use App\Http\Controllers\Api\Reports\ReportController;
use App\Http\Controllers\Api\Workflow\AuditorReviewController;
use App\Http\Controllers\Api\Workflow\DepartmentManagerReviewController;
use App\Http\Controllers\Api\Workflow\EvidenceSubmissionController;
use App\Http\Controllers\Api\Workflow\ExtensionRequestController as WorkflowExtensionRequestController;
use App\Http\Controllers\Api\Workflow\MyRequirementsController;
use App\Http\Controllers\Api\Workflow\ProgramManagerReviewController;
use App\Http\Controllers\Api\Workflow\RequirementAssignmentController;
use App\Http\Controllers\Api\Workflow\ResponsibilityController;
use App\Http\Controllers\Api\Workflow\SlaSettingController;
use App\Http\Controllers\Api\Workflow\WorkflowDashboardController;
use App\Http\Controllers\Api\Workflow\WorkflowReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // ── Public: Authentication ───────────────────────────────────────────
    Route::prefix('auth')->group(function () {
        Route::middleware('throttle:login')->group(function () {
            Route::post('login', [AuthController::class, 'login']);
            // Dev-only quick login (gated to local/debug inside the controller).
            // Registered only outside production. Both endpoints already
            // refuse there (AuthController::quickLoginEnabled()), but a
            // passwordless-login route should not exist in a production
            // route table at all — the runtime check is the second layer,
            // not the only one.
            if (! app()->environment('production')) {
                Route::post('quick-login', [AuthController::class, 'quickLogin']);
            }
        });
        if (! app()->environment('production')) {
            Route::get('dev-users', [AuthController::class, 'devUsers']);
        }
    });

    // ── Public: Branding (logo / platform name for login + shell) ────────
    Route::get('branding', [SettingController::class, 'branding']);

    // ── Protected Routes ─────────────────────────────────────────────────
    Route::middleware(['jwt.session', 'set.locale'])->group(function () {

        // Auth
        Route::prefix('auth')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::post('refresh', [AuthController::class, 'refresh']);
            Route::get('me', [AuthController::class, 'me']);
            Route::post('change-password', [AuthController::class, 'changePassword']);
        });

        // Dashboard (legacy, implicitly Qiyas-only — kept for backward compatibility)

        // Executive Dashboard foundation — cross-program aggregate, Super Admin + Executive Viewer.
        Route::get('executive-dashboard', [ExecutiveDashboardController::class, 'index'])
            ->middleware('role:super-admin|executive');

        // ── Compliance Programs (multi-program architecture) ─────────────
        Route::prefix('programs')->group(function () {
            Route::get('/', [ComplianceProgramController::class, 'index']);

            // Every route below resolves {program} by its `code` (e.g. QIYAS)
            // and enforces existence + active status + user access before
            // reaching any controller. See EnsureProgramAccess middleware.
            Route::prefix('{program}')->middleware('program.access')->group(function () {
                Route::get('/', [ComplianceProgramController::class, 'show']);
                Route::get('dashboard', [ProgramDashboardController::class, 'index']);

                Route::get('cycles', [ProgramCycleController::class, 'index']);
                Route::post('cycles', [ProgramCycleController::class, 'store']);
                Route::get('cycles/{cycle}', [ProgramCycleController::class, 'show']);
                Route::put('cycles/{cycle}', [ProgramCycleController::class, 'update']);
                Route::post('cycles/{cycle}/activate', [ProgramCycleController::class, 'activate']);
                Route::post('cycles/{cycle}/close', [ProgramCycleController::class, 'close']);
                Route::post('cycles/{cycle}/archive', [ProgramCycleController::class, 'archive']);

                // ── Structure-driven XLSX ────────────────────────────
                // Template columns follow the active structure, so a program
                // that adds a level gets a wider template with no code
                // change. See docs/dynamic-xlsx-engine.md.
                Route::get('hierarchy-template', [HierarchyImportController::class, 'template'])->middleware('throttle:reports');
                Route::get('hierarchy-export', [HierarchyImportController::class, 'export'])->middleware('throttle:reports');
                Route::post('hierarchy-import/preview', [HierarchyImportController::class, 'preview'])->middleware('throttle:uploads');
                Route::post('hierarchy-import/{importLog}/confirm', [HierarchyImportController::class, 'confirm'])->middleware('throttle:uploads');
                Route::get('hierarchy-import/{importLog}/error-report', [HierarchyImportController::class, 'errorReport'])->middleware('throttle:reports');

                // ── Generic hierarchy reporting ──────────────────────
                // Dimensions, cascading filter options and export columns
                // all derive from the program's own structure — one
                // implementation for every program and depth.
                // See docs/dynamic-reporting-engine.md.
                Route::get('reports/dimensions', [HierarchyReportController::class, 'dimensions']);
                Route::get('reports/filter-options/{levelKey}', [HierarchyReportController::class, 'filterOptions']);
                Route::get('reports/hierarchy', [HierarchyReportController::class, 'hierarchy'])->middleware('throttle:reports');
                Route::get('reports/hierarchy/export', [HierarchyReportController::class, 'export'])->middleware('throttle:reports');

                // ── Hierarchy-driven dashboard ───────────────────────
                // Universal metrics are hierarchy-neutral; drill-down is
                // driven by the program's own dashboard-visible levels, so
                // there is one endpoint per CONCERN, not one per level.
                // See docs/dynamic-dashboard-engine.md.
                Route::get('dashboard/metrics', [HierarchyDashboardController::class, 'metrics']);
                Route::get('dashboard/levels', [HierarchyDashboardController::class, 'levels']);
                Route::get('dashboard/by-level/{levelKey}', [HierarchyDashboardController::class, 'byLevel']);

                // ── Program Structure Settings ───────────────────────
                // The Program Manager's control over their OWN program's
                // hierarchy shape and terminology. Reads are open to anyone
                // with program access (labels drive every screen); writes
                // require the program-manager role for THIS program, checked
                // in HierarchyStructurePolicy. See
                // docs/program-structure-settings.md.
                Route::get('structure', [ProgramStructureController::class, 'show']);
                Route::get('structure/versions', [ProgramStructureController::class, 'versions']);
                Route::get('structure/draft', [ProgramStructureController::class, 'showDraft']);
                Route::post('structure/draft', [ProgramStructureController::class, 'openDraft']);
                Route::delete('structure/draft', [ProgramStructureController::class, 'discardDraft']);
                Route::get('structure/draft/impact', [ProgramStructureController::class, 'impact']);
                Route::post('structure/draft/activate', [ProgramStructureController::class, 'activate']);
                Route::post('structure/draft/levels', [ProgramStructureController::class, 'addLevel']);
                Route::put('structure/draft/levels/{level}', [ProgramStructureController::class, 'updateLevel']);
                Route::delete('structure/draft/levels/{level}', [ProgramStructureController::class, 'removeLevel']);
                Route::post('structure/draft/levels/{level}/move', [ProgramStructureController::class, 'moveLevel']);

                // Generic arbitrary-depth hierarchy (Phase 6) — used by ECC,
                // available to any program that configures a `hierarchy`
                // category. See docs/programs/ecc/hierarchy.md.
                Route::get('hierarchy-levels', [ComplianceHierarchyController::class, 'levels']);
                Route::get('hierarchy', [ComplianceHierarchyController::class, 'index']);
                Route::post('hierarchy', [ComplianceHierarchyController::class, 'store']);
                // Static segment before the wildcard, or "search" is read as an id.
                Route::get('hierarchy/search', [ComplianceHierarchyController::class, 'search']);
                Route::get('hierarchy/{node}', [ComplianceHierarchyController::class, 'show']);
                Route::put('hierarchy/{node}', [ComplianceHierarchyController::class, 'update']);
                Route::post('hierarchy/{node}/archive', [ComplianceHierarchyController::class, 'archive']);
                Route::get('content-versions', [ComplianceHierarchyController::class, 'contentVersions']);

                Route::get('requirements', [ProgramRequirementController::class, 'index']);
                Route::get('requirements/{requirement}', [ProgramRequirementController::class, 'show']);

                Route::prefix('reports')->group(function () {
                    Route::get('by-department', [ProgramReportController::class, 'byDepartment']);
                    Route::get('by-standard', [ProgramReportController::class, 'byStandard']);
                    Route::get('by-status', [ProgramReportController::class, 'byStatus']);
                    Route::get('cycle-summary', [ProgramReportController::class, 'cycleSummary']);
                    // Phase 2 workflow reports
                    Route::get('overdue-requirements', [WorkflowReportController::class, 'overdueRequirements']);
                    Route::get('sla-breaches', [WorkflowReportController::class, 'slaBreaches']);
                    Route::get('extension-requests', [WorkflowReportController::class, 'extensionRequests']);
                    Route::get('rejection-frequency', [WorkflowReportController::class, 'rejectionFrequency']);
                    Route::get('employee-performance', [WorkflowReportController::class, 'employeePerformance']);
                });

                // ── Phase 2: operational workflow ─────────────────────────
                Route::prefix('assignments')->group(function () {
                    Route::get('/', [RequirementAssignmentController::class, 'index']);
                    Route::post('/', [RequirementAssignmentController::class, 'store']);
                    Route::get('{assignment}', [RequirementAssignmentController::class, 'show']);
                    Route::put('{assignment}', [RequirementAssignmentController::class, 'update']);
                    Route::post('{assignment}/reassign', [RequirementAssignmentController::class, 'reassign']);
                    Route::get('{assignment}/history', [RequirementAssignmentController::class, 'history']);
                    Route::post('{assignment}/draft', [EvidenceSubmissionController::class, 'openDraft']);
                    Route::post('{assignment}/extension-requests', [WorkflowExtensionRequestController::class, 'store']);
                    Route::get('{assignment}/extension-requests', [WorkflowExtensionRequestController::class, 'forAssignment']);
                    // Phase 7: generic responsibility labels (Data Owner,
                    // Data Steward, ...) — see docs/programs/ndmo/responsibilities.md.
                    Route::get('{assignment}/responsibilities', [ResponsibilityController::class, 'index']);
                    Route::post('{assignment}/responsibilities', [ResponsibilityController::class, 'store']);
                });

                Route::get('responsibility-types', [ResponsibilityController::class, 'types']);
                Route::delete('responsibilities/{responsibility}', [ResponsibilityController::class, 'destroy']);
                Route::get('departments/{department}/users', [ResponsibilityController::class, 'departmentUsers']);

                Route::get('my-requirements', [MyRequirementsController::class, 'index']);

                Route::prefix('evidence-submissions')->group(function () {
                    Route::get('{submission}', [EvidenceSubmissionController::class, 'show']);
                    Route::get('{submission}/timeline', [EvidenceSubmissionController::class, 'timeline']);
                    Route::post('{submission}/files', [EvidenceSubmissionController::class, 'uploadFile'])->middleware('throttle:uploads');
                    Route::post('{submission}/submit', [EvidenceSubmissionController::class, 'submit']);
                });
                Route::delete('evidence-files/{file}', [EvidenceSubmissionController::class, 'removeFile']);
                Route::get('evidence-files/{file}/download', [EvidenceSubmissionController::class, 'downloadFile']);

                Route::post('extension-requests/{extensionRequest}/cancel', [WorkflowExtensionRequestController::class, 'cancel']);

                Route::get('sla-settings', [SlaSettingController::class, 'show']);
                Route::put('sla-settings', [SlaSettingController::class, 'update']);

                Route::prefix('dashboards')->group(function () {
                    Route::get('program-manager', [WorkflowDashboardController::class, 'programManager']);
                    Route::get('department-manager', [WorkflowDashboardController::class, 'departmentManager']);
                    Route::get('auditor', [WorkflowDashboardController::class, 'auditor']);
                    Route::get('employee', [WorkflowDashboardController::class, 'employee']);
                });

                Route::prefix('reviews')->group(function () {
                    Route::prefix('department-manager')->group(function () {
                        Route::get('/', [DepartmentManagerReviewController::class, 'index']);
                        Route::post('{submission}/approve', [DepartmentManagerReviewController::class, 'approve']);
                        Route::post('{submission}/reject', [DepartmentManagerReviewController::class, 'reject']);
                    });
                    Route::prefix('auditor')->group(function () {
                        Route::get('/', [AuditorReviewController::class, 'index']);
                        Route::post('{submission}/approve', [AuditorReviewController::class, 'approve']);
                        Route::post('{submission}/reject', [AuditorReviewController::class, 'reject']);
                        Route::get('extension-requests', [AuditorReviewController::class, 'extensionRequests']);
                        Route::post('extension-requests/{extensionRequest}/approve', [AuditorReviewController::class, 'approveExtension']);
                        Route::post('extension-requests/{extensionRequest}/reject', [AuditorReviewController::class, 'rejectExtension']);
                    });
                    Route::prefix('program-manager')->group(function () {
                        Route::get('/', [ProgramManagerReviewController::class, 'index']);
                        Route::post('{submission}/approve', [ProgramManagerReviewController::class, 'approve']);
                        Route::post('{submission}/reject', [ProgramManagerReviewController::class, 'reject']);
                    });
                });
            });
        });

        // Notifications
        Route::prefix('notifications')->group(function () {
            Route::get('/', [NotificationController::class, 'index']);
            Route::get('count', [NotificationController::class, 'count']);
            Route::post('mark-all-read', [NotificationController::class, 'markAllRead']);
            Route::post('{id}/read', [NotificationController::class, 'markRead']);
            Route::delete('{id}', [NotificationController::class, 'destroy']);
        });

        // Departments (read + write both permission-gated; employees lack departments.view)
        // Departments are global, shared reference data (see
        // docs/cross-program-isolation.md, "Shared and Isolated Entities")
        // — read access is authorized inside the controller for ANY user
        // with at least one active program membership, not gated behind a
        // platform-wide spatie permission a program-scoped-only user (e.g.
        // a Sumoud Program Manager) would never hold. Write actions remain
        // permission-gated below; they are rare, cross-program, and
        // deliberately not opened up to every program role.
        Route::get('departments', [DepartmentController::class, 'index']);
        Route::get('departments/{department}', [DepartmentController::class, 'show']);
        Route::post('departments', [DepartmentController::class, 'store'])->middleware('permission:departments.create');
        Route::put('departments/{department}', [DepartmentController::class, 'update'])->middleware('permission:departments.edit');
        Route::delete('departments/{department}', [DepartmentController::class, 'destroy'])->middleware('permission:departments.delete');

        // Assessment Cycles
        Route::prefix('cycles')->group(function () {
            Route::get('/', [AssessmentCycleController::class, 'index']);
            Route::post('/', [AssessmentCycleController::class, 'store'])->middleware('permission:cycles.create');
            Route::get('{cycle}', [AssessmentCycleController::class, 'show']);
            Route::put('{cycle}', [AssessmentCycleController::class, 'update'])->middleware('permission:cycles.edit');
            Route::post('{cycle}/activate', [AssessmentCycleController::class, 'activate'])->middleware('permission:cycles.activate');
            Route::post('{cycle}/close', [AssessmentCycleController::class, 'close'])->middleware('permission:cycles.close');
            Route::post('{cycle}/archive', [AssessmentCycleController::class, 'archive'])->middleware('permission:cycles.archive');

        });

        // Reports
        Route::prefix('reports')->middleware('role:super-admin|qiyas-admin|auditor|executive')->group(function () {
            Route::get('by-department', [ReportController::class, 'byDepartment']);
            Route::get('by-standard', [ReportController::class, 'byStandard']);
            Route::get('by-status', [ReportController::class, 'byStatus']);
            Route::get('cycle-summary', [ReportController::class, 'cycleSummary']);
            // Exports
            Route::get('export/department-excel', [ExportController::class, 'departmentExcel'])->middleware('throttle:reports');
            Route::get('export/department-pdf', [ExportController::class, 'departmentPdf'])->middleware('throttle:reports');
            Route::get('export/cycle-summary-pdf', [ExportController::class, 'cycleSummaryPdf'])->middleware('throttle:reports');
        });

        // Admin Routes
        Route::prefix('admin')->middleware('role:super-admin')->group(function () {
            // Operational readiness (protected — see docs/qiyas-operational-runbook.md)
            Route::get('health', [HealthController::class, 'readiness']);

            // Users
            Route::get('users', [UserController::class, 'index']);
            Route::post('users', [UserController::class, 'store']);
            Route::get('users/ldap-search', [UserController::class, 'ldapSearch']);
            Route::post('users/import-ldap', [UserController::class, 'importLdap']);
            Route::get('users/{user}', [UserController::class, 'show']);
            Route::put('users/{user}', [UserController::class, 'update']);
            Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword']);
            Route::post('users/{user}/toggle-active', [UserController::class, 'toggleActive']);

            // Settings
            Route::get('settings', [SettingController::class, 'index']);
            Route::get('settings/{group}', [SettingController::class, 'group']);
            Route::post('settings', [SettingController::class, 'update']);

            // Phase 8: versioned, validated/sanitized branding assets — the sole
            // branding upload path (see docs/administration/branding.md). Supersedes
            // an earlier extension-only-validated settings/branding/upload endpoint,
            // which has been removed rather than hardened in place.
            Route::get('branding/{type}', [BrandingController::class, 'history']);
            Route::post('branding/{type}/upload', [BrandingController::class, 'upload']);
            Route::post('branding/{type}/{asset}/activate', [BrandingController::class, 'activate']);
            Route::post('branding/{type}/{asset}/restore', [BrandingController::class, 'restore']);

            // Phase 8: SMTP settings (see docs/administration/smtp-settings.md)
            Route::get('smtp-settings', [SmtpSettingsController::class, 'show']);
            Route::put('smtp-settings', [SmtpSettingsController::class, 'update']);
            Route::post('smtp-settings/test', [SmtpSettingsController::class, 'test']);
            Route::get('smtp-settings/history', [SmtpSettingsController::class, 'history']);

            // Email delivery log
            Route::get('email-logs', [EmailLogController::class, 'index']);

            // Email notification templates (Phase 2)
            Route::get('email-templates', [EmailTemplateController::class, 'index']);
            Route::get('email-templates/{template}', [EmailTemplateController::class, 'show']);
            Route::put('email-templates/{template}', [EmailTemplateController::class, 'update']);
            Route::post('email-templates/{template}/preview', [EmailTemplateController::class, 'preview']);
            Route::post('email-templates/{template}/test-send', [EmailTemplateController::class, 'testSend']);
        });

        // Audit Logs — super-admin (all), qiyas-admin, and auditor (review history).
        Route::get('admin/audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit-logs.view');
    });
});
