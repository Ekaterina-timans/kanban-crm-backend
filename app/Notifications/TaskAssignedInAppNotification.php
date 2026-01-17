<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TaskAssignedInAppNotification extends Notification
{
    use Queueable;

    public function __construct(public Task $task) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type' => 'task_assigned',
            'task_id' => $this->task->id,
            'task_name' => $this->task->name,
            'column_id' => $this->task->column_id,
            'assignee_id' => $this->task->assignee_id,
            'author_id' => $this->task->author_id,
            'due_date' => $this->task->due_date,
        ];
    }
}