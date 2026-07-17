<?php

namespace App\Services;

use App\Models\EvidenceSubmission;
use App\Models\Setting;
use Illuminate\Http\UploadedFile;

/**
 * Configurable evidence file rules (platform-level `evidence_upload`
 * settings group, editable by Super Admin like every other Setting).
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

    /** Returns null if valid, or a user-facing error message (bilingual pair) if not. */
    public function validateFile(UploadedFile $file): ?array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $mime = $file->getMimeType();

        if (in_array($ext, self::DANGEROUS_EXTENSIONS, true)) {
            return ['ar' => 'نوع الملف غير مسموح لأسباب أمنية.', 'en' => 'This file type is not allowed for security reasons.'];
        }
        if (in_array($mime, self::DANGEROUS_MIME_PATTERNS, true)) {
            return ['ar' => 'تم رفض الملف: نوع محتوى غير آمن.', 'en' => 'File rejected: unsafe content type detected.'];
        }

        $allowed = $this->allowedExtensions();
        if (! in_array($ext, $allowed, true)) {
            return [
                'ar' => 'نوع الملف غير مدعوم. الأنواع المسموحة: '.implode(', ', $allowed),
                'en' => 'Unsupported file type. Allowed: '.implode(', ', $allowed),
            ];
        }

        $maxBytes = $this->maxFileSizeMb() * 1024 * 1024;
        if ($file->getSize() > $maxBytes) {
            return [
                'ar' => "حجم الملف يتجاوز الحد المسموح ({$this->maxFileSizeMb()} م.ب).",
                'en' => "File exceeds the maximum size ({$this->maxFileSizeMb()} MB).",
            ];
        }

        return null;
    }

    /** Returns null if valid, or a bilingual error if adding this file would violate submission-level limits. */
    public function validateSubmissionLimits(EvidenceSubmission $submission, UploadedFile $newFile): ?array
    {
        $existing = $submission->activeFiles;
        if ($existing->count() + 1 > $this->maxFilesPerSubmission()) {
            return [
                'ar' => "الحد الأقصى لعدد الملفات هو {$this->maxFilesPerSubmission()}.",
                'en' => "Maximum {$this->maxFilesPerSubmission()} files per submission.",
            ];
        }

        $totalBytes = $existing->sum('file_size') + $newFile->getSize();
        $maxTotalBytes = $this->maxTotalSubmissionSizeMb() * 1024 * 1024;
        if ($totalBytes > $maxTotalBytes) {
            return [
                'ar' => "الحجم الإجمالي للملفات يتجاوز الحد المسموح ({$this->maxTotalSubmissionSizeMb()} م.ب).",
                'en' => "Total submission size exceeds the maximum ({$this->maxTotalSubmissionSizeMb()} MB).",
            ];
        }

        return null;
    }

    public function allowedExtensions(): array
    {
        $raw = Setting::get('evidence_upload', 'allowed_extensions', self::DEFAULT_EXTENSIONS);

        return collect(explode(',', $raw))->map(fn ($e) => trim(strtolower($e)))->filter()->values()->all();
    }

    public function maxFileSizeMb(): int
    {
        return (int) Setting::get('evidence_upload', 'max_file_size_mb', 20);
    }

    public function maxFilesPerSubmission(): int
    {
        return (int) Setting::get('evidence_upload', 'max_files_per_submission', 10);
    }

    public function maxTotalSubmissionSizeMb(): int
    {
        return (int) Setting::get('evidence_upload', 'max_total_submission_size_mb', 100);
    }
}
