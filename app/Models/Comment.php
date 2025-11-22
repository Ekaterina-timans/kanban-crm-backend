<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
    use HasFactory;

    protected $fillable = ['task_id', 'user_id', 'content', 'reply_to_id', 'mentioned_user_ids'];

    protected $casts = [
        'mentioned_user_ids' => 'array',
    ];

    protected $appends = ['mentioned_users'];

    public function getMentionedUsersAttribute()
    {
        if (empty($this->mentioned_user_ids)) {
            return collect();
        }

        return User::whereIn('id', $this->mentioned_user_ids)->get();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function attachments()
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function replyTo() 
    { 
        return $this->belongsTo(Comment::class, 'reply_to_id'); 
    }
}
