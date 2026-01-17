<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationSetting extends Model
{
    protected $fillable = [
        'user_id',

        // in-app
        'inapp_chat_messages',
        'inapp_mentions',
        'inapp_task_assigned',
        'inapp_comments_on_my_tasks',
        'inapp_comments_on_assigned_tasks',
        'inapp_deadline_reminders',

        // email
        'email_chat_messages',
        'email_task_assigned',
        'email_comments_on_my_tasks',
        'email_comments_on_assigned_tasks',
        'email_deadline_reminders',

        // deadline extras
        'deadline_days_before',
        'deadline_notify_time',
    ];

    protected $casts = [
        // in-app
        'inapp_chat_messages' => 'boolean',
        'inapp_mentions' => 'boolean',
        'inapp_task_assigned' => 'boolean',
        'inapp_comments_on_my_tasks' => 'boolean',
        'inapp_comments_on_assigned_tasks' => 'boolean',
        'inapp_deadline_reminders' => 'boolean',

        // email
        'email_chat_messages' => 'boolean',
        'email_task_assigned' => 'boolean',
        'email_comments_on_my_tasks' => 'boolean',
        'email_comments_on_assigned_tasks' => 'boolean',
        'email_deadline_reminders' => 'boolean',

        'deadline_days_before' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
