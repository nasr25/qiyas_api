<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\Admin\SettingController;
use App\Http\Controllers\Api\Admin\AuditLogController;
use App\Http\Controllers\Api\Cycles\AssessmentCycleController;
use App\Http\Controllers\Api\Departments\DepartmentController;
use App\Http\Controllers\Api\Standards\StandardController;
use App\Http\Controllers\Api\Standards\EvidenceRequirementController;
use App\Http\Controllers\Api\Documents\DocumentController;
use App\Http\Controllers\Api\Documents\CommentController;
use App\Http\Controllers\Api\Documents\ExtensionRequestController;
use App\Http\Controllers\Api\Auditor\AuditorController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\Reports\ReportController;
use App\Http\Controllers\Api\Reports\ExportController;
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
    });

    // ── Protected Routes ─────────────────────────────────────────────────
    Route::middleware(['jwt.auth', 'set.locale'])->group(function () {

        // Auth
        Route::prefix('auth')->group(function () {
            Route::post('logout',          [AuthController::class, 'logout']);
            Route::post('refresh',         [AuthController::class, 'refresh']);
            Route::get('me',               [AuthController::class, 'me']);
            Route::post('change-password', [AuthController::class, 'changePassword']);
        });

        // Dashboard
        Route::get('dashboard', [DashboardController::class, 'index']);

        // Notifications
        Route::prefix('notifications')->group(function () {
            Route::get('/',               [NotificationController::class, 'index']);
            Route::get('count',           [NotificationController::class, 'count']);
            Route::post('mark-all-read',  [NotificationController::class, 'markAllRead']);
            Route::post('{id}/read',      [NotificationController::class, 'markRead']);
            Route::delete('{id}',         [NotificationController::class, 'destroy']);
        });

        // Departments
        Route::apiResource('departments', DepartmentController::class);

        // Assessment Cycles
        Route::prefix('cycles')->group(function () {
            Route::get('/',                         [AssessmentCycleController::class, 'index']);
            Route::post('/',                        [AssessmentCycleController::class, 'store']);
            Route::get('{cycle}',                   [AssessmentCycleController::class, 'show']);
            Route::put('{cycle}',                   [AssessmentCycleController::class, 'update']);
            Route::post('{cycle}/activate',         [AssessmentCycleController::class, 'activate']);
            Route::post('{cycle}/close',            [AssessmentCycleController::class, 'close']);
            Route::post('{cycle}/archive',          [AssessmentCycleController::class, 'archive']);

            // Standards within a cycle
            Route::prefix('{cycle}/standards')->group(function () {
                Route::get('/',          [StandardController::class, 'index']);
                Route::post('/',         [StandardController::class, 'store']);
                Route::get('{standard}', [StandardController::class, 'show']);
                Route::put('{standard}', [StandardController::class, 'update']);
                Route::delete('{standard}', [StandardController::class, 'destroy']);
            });
        });

        // Evidence Requirements (via standard)
        Route::prefix('standards/{standard}/requirements')->group(function () {
            Route::get('/',               [EvidenceRequirementController::class, 'index']);
            Route::post('/',              [EvidenceRequirementController::class, 'store']);
            Route::get('{requirement}',   [EvidenceRequirementController::class, 'show']);
            Route::put('{requirement}',   [EvidenceRequirementController::class, 'update']);
            Route::delete('{requirement}',[EvidenceRequirementController::class, 'destroy']);
        });

        // Documents
        Route::prefix('documents')->group(function () {
            Route::get('/',                                       [DocumentController::class, 'index']);
            Route::post('/',                                      [DocumentController::class, 'store']);
            Route::get('{document}',                              [DocumentController::class, 'show']);
            Route::post('{document}/upload',                      [DocumentController::class, 'upload']);
            Route::post('{document}/submit',                      [DocumentController::class, 'submit']);
            Route::get('{document}/versions/{version}/download',  [DocumentController::class, 'download']);

            // Comments
            Route::prefix('{document}/comments')->group(function () {
                Route::get('/',        [CommentController::class, 'index']);
                Route::post('/',       [CommentController::class, 'store']);
                Route::delete('{comment}', [CommentController::class, 'destroy']);
            });

            // Extension Requests
            Route::prefix('{document}/extension-requests')->group(function () {
                Route::get('/',  [ExtensionRequestController::class, 'index']);
                Route::post('/', [ExtensionRequestController::class, 'store']);
            });
        });

        // Auditor Portal
        Route::prefix('auditor')->middleware('role:auditor|super-admin')->group(function () {
            Route::get('pending-reviews',                                  [AuditorController::class, 'pendingReviews']);
            Route::post('documents/{document}/approve',                    [AuditorController::class, 'approve']);
            Route::post('documents/{document}/reject',                     [AuditorController::class, 'reject']);
            Route::get('extension-requests',                               [AuditorController::class, 'extensionRequests']);
            Route::post('extension-requests/{extensionRequest}/approve',   [AuditorController::class, 'approveExtension']);
            Route::post('extension-requests/{extensionRequest}/reject',    [AuditorController::class, 'rejectExtension']);
        });

        // Reports
        Route::prefix('reports')->middleware('role:super-admin|auditor|executive')->group(function () {
            Route::get('by-department',            [ReportController::class, 'byDepartment']);
            Route::get('by-standard',              [ReportController::class, 'byStandard']);
            Route::get('by-status',                [ReportController::class, 'byStatus']);
            Route::get('cycle-summary',            [ReportController::class, 'cycleSummary']);
            // Exports
            Route::get('export/department-excel',  [ExportController::class, 'departmentExcel']);
            Route::get('export/department-pdf',    [ExportController::class, 'departmentPdf']);
            Route::get('export/cycle-summary-pdf', [ExportController::class, 'cycleSummaryPdf']);
        });

        // Admin Routes
        Route::prefix('admin')->middleware('role:super-admin')->group(function () {
            // Users
            Route::get('users',                          [UserController::class, 'index']);
            Route::post('users',                         [UserController::class, 'store']);
            Route::get('users/ldap-search',              [UserController::class, 'ldapSearch']);
            Route::post('users/import-ldap',             [UserController::class, 'importLdap']);
            Route::get('users/{user}',                   [UserController::class, 'show']);
            Route::put('users/{user}',                   [UserController::class, 'update']);
            Route::post('users/{user}/reset-password',   [UserController::class, 'resetPassword']);
            Route::post('users/{user}/toggle-active',    [UserController::class, 'toggleActive']);

            // Settings
            Route::get('settings',                       [SettingController::class, 'index']);
            Route::get('settings/{group}',               [SettingController::class, 'group']);
            Route::post('settings',                      [SettingController::class, 'update']);
            Route::post('settings/branding/upload',      [SettingController::class, 'uploadBranding']);

            // Audit Logs
            Route::get('audit-logs',                     [AuditLogController::class, 'index']);
        });
    });
});
