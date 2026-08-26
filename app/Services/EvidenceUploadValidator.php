<?php

namespace App\Services;

use App\Models\ComplianceProgram;
use App\Models\EvidenceSubmission;
use App\Models\Setting;
use Illuminate\Http\UploadedFile;

/**
 * Evidence file rules are program-scoped (config category 'evidence') as of
 * Phase 4 — see docs/evidence-engine.md. The platform-wide `evidence_upload`
 * Setting group is kept as the fallback when a program has not been given
 * its own evidence configuration yet, so nothing regresses for a program
 * created before this category existed.
 *
 * Validates BOTH the extension and the actual detected MIME type — the
 * legacy Document upload path (see DocumentController) deliberately checks
 * extension only, because Laravel's `mimes:` rule misdetects some Office
 * formats; here we avoid that trap by checking the real MIME against an
 * explicit dangerous-type blocklist rather than a strict allowlist,
 * catching disguised executables without rejecting legitimate .docx/.xlsx.
 */
class EvidenceUploadValidator
{
    private const DEFAULT_EXTENSIONS = 'pdf,doc,docx,xls,xlsx,ppt,pptx,zip,jpg,jpeg,png';

    private const DANGEROUS_MIME_PATTERNS = [
        'application/x-msdownload', 'application/x-msdos-program', 'application/x-executable',
        // application/x-dosexec / application/vnd.microsoft.portable-executable are what
        // different libmagic versions report for a Windows PE binary (.exe/.dll)
        // regardless of what extension it was renamed to — both are listed since the
        // exact string PHP's fileinfo returns depends on the platform's magic database
        // (verified to differ between the macOS dev environment and a typical Linux
        // build); without these a renamed executable could pass the extension allowlist.
        'application/x-dosexec', 'application/vnd.microsoft.portable-executable',
        'application/x-elf', 'application/x-mach-binary',
        'application/x-sh', 'application/x-shellscript', 'application/x-bat',
        'text/x-php', 'application/x-httpd-php', 'application/java-archive',
        'application/vnd.ms-excel.sheet.macroEnabled.12', 'application/vnd.ms-word.document.macroEnabled.12',
        'application/vnd.ms-office.vbaProject',
        // Markup types that could be abused for stored XSS if ever rendered inline by a
        // future feature; blocked here even though the extension allowlist already
        // excludes them by default, as a second, independent layer of defense.
        'text/html', 'application/xhtml+xml', 'image/svg+xml',
    ];

    private const DANGEROUS_EXTENSIONS = ['exe', 'bat', 'cmd', 'sh', 'msi', 'com', 'scr', 'js', 'vbs', 'ps1', 'jar', 'app', 'php', 'phtml'];

    public function __construct(private readonly ProgramConfigurationService $config) {}

    /** Returns null if valid, or a user-facing error message (bilingual pair) if not. */
    public function validateFile(UploadedFile $file, ?ComplianceProgram $program = null): ?array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $mime = $file->getMimeType();

        if (in_array($ext, self::DANGEROUS_EXTENSIONS, true)) {
            return ['ar' => 'نوع الملف غير مسموح لأسباب أمنية.', 'en' => 'This file type is not allowed for security reasons.'];
        }
        if (in_array($mime, self::DANGEROUS_MIME_PATTERNS, true)) {
            return ['ar' => 'تم رفض الملف: نوع محتوى غير آمن.', 'en' => 'File rejected: unsafe content type detected.'];
        }

        $allowed = $this->allowedExtensions($program);
        if (! in_array($ext, $allowed, true)) {
            return [
                'ar' => 'نوع الملف غير مدعوم. الأنواع المسموحة: '.implode(', ', $allowed),
                'en' => 'Unsupported file type. Allowed: '.implode(', ', $allowed),
            ];
        }

        $maxMb = $this->maxFileSizeMb($program);
        $maxBytes = $maxMb * 1024 * 1024;
        if ($file->getSize() > $maxBytes) {
            return [
                'ar' => "حجم الملف يتجاوز الحد المسموح ({$maxMb} م.ب).",
                'en' => "File exceeds the maximum size ({$maxMb} MB).",
            ];
        }

        return null;
    }

    /** Returns null if valid, or a bilingual error if adding this file would violate submission-level limits. */
    public function validateSubmissionLimits(EvidenceSubmission $submission, UploadedFile $newFile): ?array
    {
        $program = $submission->program;
        $existing = $submission->activeFiles;

        $maxFiles = $this->maxFilesPerSubmission($program);
        if ($existing->count() + 1 > $maxFiles) {
            return [
                'ar' => "الحد الأقصى لعدد الملفات هو {$maxFiles}.",
                'en' => "Maximum {$maxFiles} files per submission.",
            ];
        }

        $totalBytes = $existing->sum('file_size') + $newFile->getSize();
        $maxTotalMb = $this->maxTotalSubmissionSizeMb($program);
        $maxTotalBytes = $maxTotalMb * 1024 * 1024;
        if ($totalBytes > $maxTotalBytes) {
            return [
                'ar' => "الحجم الإجمالي للملفات يتجاوز الحد المسموح ({$maxTotalMb} م.ب).",
                'en' => "Total submission size exceeds the maximum ({$maxTotalMb} MB).",
            ];
        }

        return null;
    }

    public function allowedExtensions(?ComplianceProgram $program = null): array
    {
        if ($program && ($list = $this->programConfig($program)['allowed_extensions'] ?? null)) {
            return collect($list)->map(fn ($e) => strtolower(trim($e)))->filter()->values()->all();
        }

        $raw = Setting::get('evidence_upload', 'allowed_extensions', self::DEFAULT_EXTENSIONS);

        return collect(explode(',', $raw))->map(fn ($e) => trim(strtolower($e)))->filter()->values()->all();
    }

    public function maxFileSizeMb(?ComplianceProgram $program = null): int
    {
        return (int) ($this->programConfig($program)['max_file_size_mb']
            ?? Setting::get('evidence_upload', 'max_file_size_mb', 20));
    }

    public function maxFilesPerSubmission(?ComplianceProgram $program = null): int
    {
        return (int) ($this->programConfig($program)['max_files_per_submission']
            ?? Setting::get('evidence_upload', 'max_files_per_submission', 10));
    }

    public function maxTotalSubmissionSizeMb(?ComplianceProgram $program = null): int
    {
        return (int) ($this->programConfig($program)['max_total_submission_size_mb']
            ?? Setting::get('evidence_upload', 'max_total_submission_size_mb', 100));
    }

    /** Empty array (not the platform default) when no program is given or none is configured yet — callers fall back to Setting themselves. */
    private function programConfig(?ComplianceProgram $program): array
    {
        return $program ? $this->config->get($program, 'evidence', []) : [];
    }
}
