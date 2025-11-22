<?php

namespace App\Events;

use App\Models\Chat;
use App\Models\ChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class MessageCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ChatMessage $message;
    public Chat $chat;

    public function __construct(ChatMessage $message, Chat $chat)
    {
        $this->message = $message;
        $this->chat = $chat;
    }

    public function broadcastOn()
    {
        // Приватный канал для чата: только участники смогут слушать
        return new PrivateChannel('chat.' . $this->chat->id);
    }

    public function broadcastAs()
    {
        // Имя события: на фронте слушаем '.message.created'
        return 'message.created';
    }

    public function broadcastWith()
    {
        // Данные, которые придут на фронт: само сообщение + chat_id
        $content = trim((string) $this->message->content);
        if ($content === '') {
            $content = 'Вложение';
        }

        return [
            'chat_id'    => $this->chat->id,
            'message_id' => $this->message->id,
            'preview'    => Str::limit($content, 80),
            'user_id'    => $this->message->user_id,
            'created_at' => $this->message->created_at->toISOString(),
        ];
    }

    // Отправляем только другим участникам чата (кроме sender)
    // public function broadcastToOthers()
    // {
    //     return true;
    // }
}
