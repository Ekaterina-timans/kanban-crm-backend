<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Chat extends Model
{
    use HasFactory;
    
    protected $fillable = ['group_id','created_by','title','type','is_archived','last_message_id'];

    public function group() {
        return $this->belongsTo(Group::class);
    }
    public function creator() {
        return $this->belongsTo(User::class, 'created_by');
    }
    public function participants() { 
        return $this->hasMany(ChatParticipant::class);
    }
    public function messages() { 
        return $this->hasMany(ChatMessage::class); 
    }
    public function lastMessage() { 
        return $this->belongsTo(ChatMessage::class, 'last_message_id'); 
    }

    protected $appends = ['avatar_url'];

    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar ? asset('storage/'.$this->avatar) : null;
    }
}
