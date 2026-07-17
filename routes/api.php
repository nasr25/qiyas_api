<?php

use App\Http\Controllers\Api\Admin\AuditLogController;
use App\Http\Controllers\Api\Admin\EmailLogController;
use App\Http\Controllers\Api\Admin\EmailTemplateController;
use App\Http\Controllers\Api\Admin\SettingController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\Auditor\AuditorController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Cycles\AssessmentCycleController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\Departments\DepartmentController;
use App\Http\Controllers\Api\Documents\CommentController;
use App\Http\Controllers\Api\Documents\DocumentController;
use App\Http\Controllers\Api\Documents\ExtensionRequestController;
use App\Http\Controllers\Api\ExecutiveDashboardController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\Programs\ComplianceProgramController;
use App\Http\Controllers\Api\Programs\ProgramCycleController;
use App\Http\Controllers\Api\Programs\ProgramDashboardController;
use App\Http\Controllers\Api\Programs\ProgramReportController;
use App\Http\Controllers\Api\Programs\ProgramRequirementController;
use App\Http\Controllers\Api\Programs\ProgramTaxonomyController;
use App\Http\Controllers\Api\Reports\ExportController;
use App\Http\Controllers\Api\Reports\ReportController;
use App\Http\Controllers\Api\Standards\EvidenceRequirementController;
use App\Http\Controllers\Api\Standards\StandardController;
use App\Http\Controllers\Api\Workflow\AuditorReviewController;
use App\Http\Controllers\Api\Workflow\DepartmentManagerReviewController;
use App\Http\Controllers\Api\Workflow\EvidenceSubmissionController;
use App\Http\Controllers\Api\Workflow\ExtensionRequestController as WorkflowExtensionRequestController;
use App\Http\Controllers\Api\Workflow\MyRequirementsController;
use App\Http\Controllers\Api\Workflow\ProgramManagerReviewController;
use App\Http\Controllers\Api\Workflow\QiyasImportController;
use App\Http\Controllers\Api\Workflow\RequirementAssignmentController;
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
        Route::post('login', [AuthController::class, 'login']);
        // Dev-only quick login (gated to local/debug inside the controller).
        Route::post('quick-login', [AuthController::class, 'quickLogin']);
        Route::get('dev-users', [AuthController::class, 'devUsers']);
    });

    // ── Public: Branding (logo / platform name for login + shell) ────────
    Route::get('branding', [SettingController::class, 'branding']);

    // ── Protected Routes ─────────────────────────────────────────────────
    Route::middleware(['jwt.auth', 'set.locale'])->group(function () {

        // Auth
        Route::prefix('auth')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::post('refresh', [AuthController::class, 'refresh']);
            Route::get('me', [AuthController::class, 'me']);
            Route::post('change-password', [AuthController::class, 'changePassword']);
        });

        // Dashboard (legacy, implicitly Qiyas-only — kept for backward compatibility)
        Route::get('dashboard', [DashboardController::class, 'index']);

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
                Route::get('cycles/{cycle}', [ProgramCycleController::class, 'show']);

                Route::get('domains', [ProgramTaxonomyController::class, 'domains']);
                Route::get('categories', [ProgramTaxonomyController::class, 'categories']);

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
                });

                Route::get('my-requirements', [MyRequirementsController::class, 'index']);

                Route::prefix('evidence-submissions')->group(function () {
                    Route::get('{submission}', [EvidenceSubmissionController::class, 'show']);
                    Route::get('{submission}/timeline', [EvidenceSubmissionController::class, 'timeline']);
                    Route::post('{submission}/files', [EvidenceSubmissionController::class, 'uploadFile']);
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

                Route::get('requirements-template', [QiyasImportController::class, 'downloadTemplate']);
                Route::get('requirements-imports', [QiyasImportController::class, 'index']);
                Route::post('requirements-import/preview', [QiyasImportController::class, 'preview']);
                Route::post('requirements-import/{importLog}/confirm', [QiyasImportController::class, 'confirm']);
                Route::get('requirements-import/{importLog}/error-report', [QiyasImportController::class, 'errorReport']);

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
        Route::get('departments', [DepartmentController::class, 'index'])->middleware('permission:departments.view');
        Route::get('departments/{department}', [DepartmentController::class, 'show'])->middleware('permission:departments.view');
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

            // Standards within a cycle
            Route::prefix('{cycle}/standards')->group(function () {
                Route::get('/', [StandardController::class, 'index']);
                // Static segments MUST precede the {standard} wildcard.
                Route::get('template', [StandardController::class, 'template'])->middleware('permission:standards.create');
                Route::post('import', [StandardController::class, 'import'])->middleware('permission:standards.create');
                Route::post('/', [StandardController::class, 'store'])->middleware('permission:standards.create');
                Route::get('{standard}', [StandardController::class, 'show']);
                Route::put('{standard}', [StandardController::class, 'update'])->middleware('permission:standards.edit');
                Route::delete('{standard}', [StandardController::class, 'destroy'])->middleware('permission:standards.delete');
            });
        });

        // Single standard by id (not nested under a cycle)
        Route::get('standards/{standard}', [StandardController::class, 'showById']);

        // Evidence Requirements (via standard)
        Route::prefix('standards/{standard}/requirements')->group(function () {
            Route::get('/', [EvidenceRequirementController::class, 'index']);
            Route::post('/', [EvidenceRequirementController::class, 'store'])->middleware('permission:requirements.create');
            Route::get('{requirement}', [EvidenceRequirementController::class, 'show']);
            Route::put('{requirement}', [EvidenceRequirementController::class, 'update'])->middleware('permission:requirements.edit');
            Route::delete('{requirement}', [EvidenceRequirementController::class, 'destroy'])->middleware('permission:requirements.delete');
        });

        // Documents
        Route::prefix('documents')->group(function () {
            Route::get('/', [DocumentController::class, 'index']);
            Route::post('/', [DocumentController::class, 'store'])->middleware('permission:documents.create');
            Route::get('{document}', [DocumentController::class, 'show']);
            Route::post('{document}/upload', [DocumentController::class, 'upload'])->middleware('permission:documents.upload');
            Route::post('{document}/submit', [DocumentController::class, 'submit'])->middleware('permission:documents.submit');
            Route::get('{document}/versions/{version}/download', [DocumentController::class, 'download'])->middleware('permission:documents.download');

            // Comments
            Route::prefix('{document}/comments')->group(function () {
                Route::get('/', [CommentController::class, 'index']);
                Route::post('/', [CommentController::class, 'store'])->middleware('permission:comments.create');
                Route::delete('{comment}', [CommentController::class, 'destroy'])->middleware('permission:comments.delete');
            });

            // Extension Requests
            Route::prefix('{document}/extension-requests')->group(function () {
                Route::get('/', [ExtensionRequestController::class, 'index']);
                Route::post('/', [ExtensionRequestController::class, 'store'])->middleware('permission:extensions.create');
            });
        });

        // Auditor Portal
        Route::prefix('auditor')->middleware('role:auditor|super-admin')->group(function () {
            Route::get('pending-reviews', [AuditorController::class, 'pendingReviews']);
            Route::post('documents/{document}/approve', [AuditorController::class, 'approve']);
            Route::post('documents/{document}/reject', [AuditorController::class, 'reject']);
            Route::get('extension-requests', [AuditorController::class, 'extensionRequests']);
            Route::post('extension-requests/{extensionRequest}/approve', [AuditorController::class, 'approveExtension']);
            Route::post('extension-requests/{extensionRequest}/reject', [AuditorController::class, 'rejectExtension']);
        });

        // Reports
        Route::prefix('reports')->middleware('role:super-admin|qiyas-admin|auditor|executive')->group(function () {
            Route::get('by-department', [ReportController::class, 'byDepartment']);
            Route::get('by-standard', [ReportController::class, 'byStandard']);
            Route::get('by-status', [ReportController::class, 'byStatus']);
            Route::get('cycle-summary', [ReportController::class, 'cycleSummary']);
            // Exports
            Route::get('export/department-excel', [ExportController::class, 'departmentExcel']);
            Route::get('export/department-pdf', [ExportController::class, 'departmentPdf']);
            Route::get('export/cycle-summary-pdf', [ExportController::class, 'cycleSummaryPdf']);
        });

        // Admin Routes
        Route::prefix('admin')->middleware('role:super-admin')->group(function () {
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
            Route::post('settings/branding/upload', [SettingController::class, 'uploadBranding']);

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
