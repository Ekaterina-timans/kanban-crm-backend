<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChannelMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'channel_thread_id',
        'direction',
        'external_message_id',
        'external_update_id',
        'sender_external_id',
        'text',
        'payload',
        'provider_date',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
