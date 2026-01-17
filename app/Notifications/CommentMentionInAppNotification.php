<?php

namespace App\Notifications;

use App\Models\Comment;
use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class CommentMentionInAppNotification extends Notification
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
        return [
            'type' => 'comment_mention',
            'task_id' => $this->task->id,
            'comment_id' => $this->comment->id,
            'from_user_id' => $this->comment->user_id,
            'text' => $this->comment->content ?? null,
            'created_at' => $this->comment->created_at,
        ];
    }
}