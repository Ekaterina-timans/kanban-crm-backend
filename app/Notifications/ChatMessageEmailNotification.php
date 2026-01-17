<?php

namespace App\Notifications;

use App\Models\Chat;
use App\Models\ChatMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class ChatMessageEmailNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Chat $chat,
        public ChatMessage $message
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $text = trim((string)($this->message->content ?? ''));
        if ($text === '') {
            $text = 'В чате новое сообщение.';
        }

        return (new MailMessage)
            ->subject('Новое сообщение в чате')
            ->line(Str::limit($text, 200))
            ->line('Откройте приложение, чтобы ответить.');
    }
}