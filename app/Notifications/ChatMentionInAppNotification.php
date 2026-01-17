<?php

namespace App\Notifications;

use App\Models\Chat;
use App\Models\ChatMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ChatMentionInAppNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Chat $chat,
        public ChatMessage $message
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type' => 'chat_mention',
            'chat_id' => $this->chat->id,
            'message_id' => $this->message->id,
            'from_user_id' => $this->message->user_id,
            'text' => $this->message->content ?? null,
            'created_at' => $this->message->created_at,
        ];
    }
}