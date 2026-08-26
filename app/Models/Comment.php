<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Comment supports threaded discussions on Documents and Requirements.
 * Uses polymorphic relationship for extensibility.
 */
class Comment extends Model
{
    use HasFactory;

    protected $fillable = ['commentable_type', 'commentable_id', 'compliance_program_id', 'user_id', 'body', 'parent_id'];

    protected static function booted(): void
    {
        static::creating(function (self $comment) {
            if (! $comment->compliance_program_id) {
                $commentable = $comment->commentable;
                if ($commentable && isset($commentable->compliance_program_id)) {
                    $comment->compliance_program_id = $commentable->compliance_program_id;
                }
            }
        });
    }

    // ─── Relationships ───────────────────────────────────────────────────────

    public function commentable()
    {
        return $this->morphTo();
    }

    public function program()
    {
        return $this->belongsTo(ComplianceProgram::class, 'compliance_program_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function parent()
    {
        return $this->belongsTo(Comment::class, 'parent_id');
    }

    public function replies()
    {
        return $this->hasMany(Comment::class, 'parent_id')->orderBy('created_at');
    }

    public function attachments()
    {
        return $this->hasMany(CommentAttachment::class);
    }
}
