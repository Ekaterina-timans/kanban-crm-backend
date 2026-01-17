<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class DeadlineReminderEmailNotification extends Notification
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
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        $subject = 'Напоминание о дедлайне задачи';

        $text = $this->daysBefore === 0
            ? 'Сегодня истекает срок задачи.'
            : ($this->daysBefore === 1
                ? 'Завтра истекает срок задачи.'
                : "Через {$this->daysBefore} дн. истекает срок задачи."
            );

        return (new MailMessage)
            ->subject($subject)
            ->line($text)
            ->line("Задача: {$this->task->name}")
            ->line("Срок: {$this->task->due_date}");
    }
}
