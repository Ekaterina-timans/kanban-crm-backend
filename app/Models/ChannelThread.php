<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChannelThread extends Model
{
    use HasFactory;

    protected $fillable = [
        'group_channel_id',
        'external_chat_id',
        'external_peer_id',
        'thread_type',
        'title',
        'username',
        'first_name',
        'last_name',
        'last_update_id',
        'meta',
        'last_message_text',
        'last_message_at',
        'last_message_external_id',
    ];

    protected $casts = [
        'meta' => 'array',
        'last_message_at' => 'datetime',
    ];

    public function channel()
    {
        return $this->belongsTo(GroupChannel::class, 'group_channel_id');
    }

    public function messages()
    {
        return $this->hasMany(ChannelMessage::class, 'channel_thread_id');
    }
}
