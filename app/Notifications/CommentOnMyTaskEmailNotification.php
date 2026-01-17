<?php

namespace App\Notifications;

use App\Models\Comment;
use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class CommentOnMyTaskEmailNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Task $task,
        public Comment $comment
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $text = trim((string)($this->comment->content ?? ''));
        if ($text === '') $text = 'Новый комментарий к задаче';

        return (new MailMessage)
            ->subject('Новый комментарий к вашей задаче')
            ->line('Задача: '.$this->task->name)
            ->line(Str::limit($text, 300))
            ->line('Откройте приложение, чтобы ответить.');
    }
}