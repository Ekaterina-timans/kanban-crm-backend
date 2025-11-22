<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatParticipant extends Model
{
    use HasFactory;
    public $timestamps = false;
    protected $fillable = ['chat_id','user_id','role','joined_at','last_read_message_id'];

    public function chat() 
    {
        return $this->belongsTo(Chat::class); 
    }

    public function user() 
    { 
        return $this->belongsTo(User::class); 
    }

    public function lastReadMessage() 
    { 
        return $this->belongsTo(ChatMessage::class, 'last_read_message_id'); 
    }
    
    public function canAddParticipants(): bool
    {
        return in_array($this->role, ['owner', 'admin'], true);
    }

    public function canRemoveParticipants(): bool
    {
        return $this->role === 'owner';
    }

    public function canChangeRoles(): bool
    {
        return $this->role === 'owner';
    }

    public function canUpdateAvatar(): bool
    {
        return in_array($this->role, ['owner', 'admin'], true);
    }

    public function canClearHistory(): bool
    {
        // Только для себя, а не для всех
        return true;
    }

    public function canDeleteChat(): bool
    {
        return $this->role === 'owner';
    }

    public function canLeaveChat(): bool
    {
        return true; // любой участник может выйти
    }
}
