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

    protected $fillable = ['commentable_type', 'commentable_id', 'user_id', 'body', 'parent_id'];

    // ─── Relationships ───────────────────────────────────────────────────────

    public function commentable()
    {
        return $this->morphTo();
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
