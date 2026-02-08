<?php

namespace App\Notifications;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ChatMessageInAppNotification extends Notification
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
        $from = $this->message->user;

        return [
            'type' => 'chat_message',
            'chat_id' => $this->chat->id,
            'chat_type' => $this->chat->type,
            'chat_title' => $this->chat->type === 'group' ? ($this->chat->title ?? null) : null,
            'message_id' => $this->message->id,
            'from_user_id' => $this->message->user_id,
            'from_user_email' => $from->email,
            'text' => $this->message->content ?? null,
            'created_at' => $this->message->created_at,
        ];
    }
}