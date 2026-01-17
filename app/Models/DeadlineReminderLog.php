<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeadlineReminderLog extends Model
{
    protected $fillable = [
        'user_id',
        'task_id',
        'reminder_date',
        'days_before',
        'sent_at',
    ];

    protected $casts = [
        'reminder_date' => 'date',
        'sent_at' => 'datetime',
    ];
}
