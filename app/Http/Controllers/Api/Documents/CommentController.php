<?php

namespace App\Http\Controllers\Api\Documents;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Document;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Comment and discussion threads on documents.
 */
class CommentController extends Controller
{
    /**
     * Lists comments for a document.
     * GET /api/v1/documents/{document}/comments
     */
    public function index(Document $document): JsonResponse
    {
        $comments = Comment::with(['user', 'replies.user', 'attachments'])
            ->where('commentable_type', Document::class)
            ->where('commentable_id', $document->id)
            ->whereNull('parent_id')
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $comments->map(fn ($c) => $this->formatComment($c)),
        ]);
    }

    /**
     * Adds a comment or reply.
     * POST /api/v1/documents/{document}/comments
     */
    public function store(Request $request, Document $document): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'parent_id' => ['nullable', 'exists:comments,id'],
        ]);

        $comment = Comment::create([
            'commentable_type' => Document::class,
            'commentable_id' => $document->id,
            'user_id' => $request->user()->id,
            'body' => $data['body'],
            'parent_id' => $data['parent_id'] ?? null,
        ]);

        // Handle attachments
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $path = $file->store("comments/{$document->id}", 'private');
                $comment->attachments()->create([
                    'file_path' => $path,
                    'original_filename' => $file->getClientOriginalName(),
                    'file_size' => $file->getSize(),
                    'file_type' => $file->getMimeType(),
                ]);
            }
        }

        AuditService::log('comment.added', "Comment added to document #{$document->id}", $document);

        return response()->json([
            'success' => true,
            'data' => $this->formatComment($comment->load(['user', 'attachments'])),
        ], 201);
    }

    /**
     * Deletes a comment (own comments only, or admin).
     * DELETE /api/v1/documents/{document}/comments/{comment}
     */
    public function destroy(Request $request, Document $document, Comment $comment): JsonResponse
    {
        if ($comment->user_id !== $request->user()->id && ! $request->user()->hasRole('super-admin')) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $comment->delete();

        return response()->json(['success' => true, 'message' => 'Comment deleted.']);
    }

    /** Formats a comment for API response. */
    private function formatComment(Comment $comment): array
    {
        return [
            'id' => $comment->id,
            'body' => $comment->body,
            'created_at' => $comment->created_at->toIso8601String(),
            'user' => $comment->user ? [
                'id' => $comment->user->id,
                'name' => $comment->user->name,
                'avatar_url' => $comment->user->avatar_url,
            ] : null,
            'attachments' => $comment->attachments->map(fn ($a) => [
                'id' => $a->id,
                'filename' => $a->original_filename,
                'size' => $a->file_size,
            ])->toArray(),
            'replies' => $comment->relationLoaded('replies')
                ? $comment->replies->map(fn ($r) => $this->formatComment($r))->toArray()
                : [],
        ];
    }
}
