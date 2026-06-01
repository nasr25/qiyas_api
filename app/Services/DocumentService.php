<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\EvidenceRequirement;
use App\Models\User;
use App\Notifications\DocumentApprovedNotification;
use App\Notifications\DocumentRejectedNotification;
use App\Notifications\DocumentSubmittedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * DocumentService manages the full document lifecycle:
 * upload, versioning, submission, review, approval, and rejection.
 */
class DocumentService
{
    /**
     * Uploads a new file version for a document.
     * Always creates a new version record; never overwrites.
     *
     * @param Document     $document      Target document
     * @param UploadedFile $file          Uploaded file
     * @param User         $uploader      User performing the upload
     * @param string|null  $changeReason  Reason for this version
     * @return DocumentVersion
     */
    public function uploadVersion(
        Document $document,
        UploadedFile $file,
        User $uploader,
        ?string $changeReason = null
    ): DocumentVersion {
        return DB::transaction(function () use ($document, $file, $uploader, $changeReason) {
            $nextVersion = $document->current_version + 1;
            $directory   = "documents/{$document->cycle_id}/{$document->department_id}/{$document->id}";
            $filename    = "v{$nextVersion}_{$file->getClientOriginalName()}";

            // Store in private disk
            $path = $file->storeAs($directory, $filename, 'private');

            $version = DocumentVersion::create([
                'document_id'       => $document->id,
                'version_number'    => $nextVersion,
                'file_path'         => $path,
                'original_filename' => $file->getClientOriginalName(),
                'file_size'         => $file->getSize(),
                'file_type'         => $file->getMimeType(),
                'file_hash'         => hash_file('sha256', $file->getRealPath()),
                'change_reason'     => $changeReason,
                'uploaded_by'       => $uploader->id,
                'uploaded_at'       => now(),
            ]);

            $document->update(['current_version' => $nextVersion]);

            AuditService::log(
                'document.upload',
                "Uploaded version {$nextVersion} of document #{$document->id}",
                $document,
                null,
                ['version' => $nextVersion, 'filename' => $file->getClientOriginalName()]
            );

            return $version;
        });
    }

    /**
     * Submits a document for auditor review.
     * Changes status from draft/rejected to under_review.
     *
     * @param Document $document
     * @param User     $submitter
     * @return Document
     */
    public function submit(Document $document, User $submitter): Document
    {
        if (!in_array($document->status, ['draft', 'rejected'])) {
            throw new \RuntimeException('Document cannot be submitted in its current state.');
        }

        if ($document->current_version === 0) {
            throw new \RuntimeException('Cannot submit a document without an uploaded file.');
        }

        $document->update([
            'status'       => 'under_review',
            'submitted_by' => $submitter->id,
            'submitted_at' => now(),
        ]);

        AuditService::log('document.submitted', "Document #{$document->id} submitted for review", $document);

        // Notify auditors
        $this->notifyAuditors(new DocumentSubmittedNotification($document));

        return $document->fresh();
    }

    /**
     * Approves a document after auditor review.
     *
     * @param Document $document
     * @param User     $auditor
     * @return Document
     */
    public function approve(Document $document, User $auditor): Document
    {
        if ($document->status !== 'under_review') {
            throw new \RuntimeException('Only documents under review can be approved.');
        }

        $document->update([
            'status'      => 'approved',
            'reviewed_by' => $auditor->id,
            'reviewed_at' => now(),
        ]);

        AuditService::log('document.approved', "Document #{$document->id} approved", $document);

        // Notify submitter
        if ($document->submitter) {
            $document->submitter->notify(new DocumentApprovedNotification($document));
        }

        return $document->fresh();
    }

    /**
     * Rejects a document with a mandatory reason.
     *
     * @param Document $document
     * @param User     $auditor
     * @param string   $reason  Rejection explanation
     * @return Document
     */
    public function reject(Document $document, User $auditor, string $reason): Document
    {
        if ($document->status !== 'under_review') {
            throw new \RuntimeException('Only documents under review can be rejected.');
        }

        $document->update([
            'status'           => 'rejected',
            'reviewed_by'      => $auditor->id,
            'reviewed_at'      => now(),
            'rejection_reason' => $reason,
        ]);

        AuditService::log('document.rejected', "Document #{$document->id} rejected: {$reason}", $document);

        if ($document->submitter) {
            $document->submitter->notify(new DocumentRejectedNotification($document, $reason));
        }

        return $document->fresh();
    }

    /**
     * Generates a temporary signed download URL for a document version.
     * Files are never directly accessible.
     *
     * @param DocumentVersion $version  The version to download
     * @return string  Signed URL
     */
    public function getDownloadUrl(DocumentVersion $version): string
    {
        return Storage::disk('private')->temporaryUrl(
            $version->file_path,
            now()->addMinutes(5)
        );
    }

    /**
     * Returns the raw file contents for a private download response.
     *
     * @param DocumentVersion $version
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function streamDownload(DocumentVersion $version): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        AuditService::log(
            'document.download',
            "Downloaded version {$version->version_number} of document #{$version->document_id}",
            $version->document
        );

        return Storage::disk('private')->download(
            $version->file_path,
            $version->original_filename
        );
    }

    /** Notifies all users with the Auditor role. */
    private function notifyAuditors(mixed $notification): void
    {
        $auditors = \App\Models\User::role('auditor')->where('is_active', true)->get();
        foreach ($auditors as $auditor) {
            $auditor->notify($notification);
        }
    }
}
