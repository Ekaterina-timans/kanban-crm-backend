<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatMessage extends Model
{
    use HasFactory;
    protected $fillable = ['chat_id', 'user_id', 'content', 'kind', 'reply_to_id', 'meta', 'mentioned_user_ids'];

    protected $casts = [
        'meta' => 'array',
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


    public function chat() { 
        return $this->belongsTo(Chat::class); 
    }

    public function user() { 
        return $this->belongsTo(User::class); 
    }

    public function replyTo() { 
        return $this->belongsTo(ChatMessage::class, 'reply_to_id'); 
    }

    public function attachments() 
    { 
        return $this->morphMany(Attachment::class, 'attachable'); 
    }
}
