<?php

namespace App\Notifications;

use App\Models\Comment;
use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class CommentOnAssignedTaskInAppNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Task $task,
        public Comment $comment
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        $from = $this->comment->user;
        
        return [
            'type' => 'comment_on_assigned_task',
            'task_id' => $this->task->id,
            'task_name' => $this->task->name,
            'comment_id' => $this->comment->id,
            'from_user_id' => $this->comment->user_id,
            'from_user_email' => $from->email,
            'text' => $this->comment->content ?? null,
            'created_at' => $this->comment->created_at,
        ];
    }
}