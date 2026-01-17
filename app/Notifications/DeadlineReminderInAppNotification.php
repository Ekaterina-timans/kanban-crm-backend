<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

// Разделение уведомлений на 2класса
class DeadlineReminderInAppNotification extends Notification
{
    use Queueable;

    public Task $task;
    public int $daysBefore;

    public function __construct(Task $task, int $daysBefore)
    {
        $this->task = $task;
        $this->daysBefore = $daysBefore;
    }

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toDatabase($notifiable)
    {
        return [
            'type' => 'deadline_reminder',
            'task_id' => $this->task->id,
            'task_name' => $this->task->name,
            'column_id' => $this->task->column_id,
            'due_date' => $this->task->due_date,
            'days_before' => $this->daysBefore,
        ];
    }
}
