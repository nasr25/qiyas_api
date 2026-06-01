<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * CommentAttachment stores files attached to comments.
 */
class CommentAttachment extends Model
{
    use HasFactory;

    protected $fillable = ['comment_id', 'file_path', 'original_filename', 'file_size', 'file_type'];

    public function comment()
    {
        return $this->belongsTo(Comment::class);
    }
}
