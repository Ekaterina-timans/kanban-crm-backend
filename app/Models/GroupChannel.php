<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GroupChannel extends Model
{
    use HasFactory;

    protected $fillable = [
        'group_id',
        'provider',
        'display_name',
        'status',
        'settings',
        'secrets',
    ];

    protected $casts = [
        'settings' => 'array',
        'secrets'  => 'array',
    ];

    public function group()
    {
        return $this->belongsTo(Group::class);
    }

    public function threads()
    {
        return $this->hasMany(ChannelThread::class, 'group_channel_id');
    }
}
