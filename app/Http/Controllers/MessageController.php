<?php

namespace App\Http\Controllers;

use App\Events\MessageCreated;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Notifications\ChatMentionInAppNotification;
use App\Notifications\ChatMessageEmailNotification;
use App\Notifications\ChatMessageInAppNotification;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class MessageController extends Controller
{
    public function index(Request $req, Chat $chat)
    {
        $this->authorizeMember($req->user()->id, $chat->id);

        // валидация курсоров
        $data = $req->validate([
            'limit'     => 'sometimes|integer|min:1|max:100',
            'before_id' => 'sometimes|integer|min:1',
            'after_id'  => 'sometimes|integer|min:1',
        ]);

        // нельзя одновременно и before, и after
        if (!empty($data['before_id']) && !empty($data['after_id'])) {
            return response()->json([
                'message' => 'Use either before_id or after_id, not both.'
            ], 422);
        }

        $limit    = (int)($data['limit'] ?? 30);
        $beforeId = $data['before_id'] ?? null;
        $afterId  = $data['after_id']  ?? null;

        // собираем запрос
        $q = ChatMessage::query()
            ->with([
                'user:id,name,email,avatar',
                'replyTo:id,chat_id,user_id,content',
                'replyTo.user:id,name,email',
                'attachments:id,attachable_id,attachable_type,original_name,mime,size,disk,path,meta,created_at'
            ])
            ->where('chat_id', $chat->id);

       if ($beforeId && !$afterId) {
            // старые сообщения (пагинация вверх)
            $q->where('id', '<', $beforeId)->orderByDesc('id');
        } elseif ($afterId && !$beforeId) {
            // новые сообщения (догружаем снизу)
            $q->where('id', '>', $afterId)->orderBy('id');
        } else {
            // первый заход: последние N
            $q->orderByDesc('id');
        }

        $rows = $q->limit($limit)->get();

        // фронту удобнее ASC (старые -> новые)
        $messages = $rows->sortBy('id')->values();

        $oldestId = $messages->first()->id ?? null;
        $newestId = $messages->last()->id ?? null;

        // флаги наличия ещё
        $hasOlder = $oldestId
            ? (bool) ChatMessage::where('chat_id', $chat->id)
                  ->where('id', '<', $oldestId)
                  ->orderByDesc('id')
                  ->value('id')
            : false;

        $hasNewer = $newestId
            ? (bool) ChatMessage::where('chat_id', $chat->id)
                  ->where('id', '>', $newestId)
                  ->orderBy('id')
                  ->value('id')
            : false;

        return response()->json([
            'messages' => $messages,
            'cursors'  => [
                'oldest_id' => $oldestId,
                'newest_id' => $newestId,
                'has_older' => $hasOlder,
                'has_newer' => $hasNewer,
            ],
        ]);
    }

    public function store(Request $req, Chat $chat)
    {
        $this->authorizeMember($req->user()->id, $chat->id);

        $data = $req->validate([
            'content'     => 'nullable|string',
            'kind'        => 'in:text,system,poll',
            'reply_to_id' => 'nullable|exists:chat_messages,id',
            'meta'        => 'nullable|array',
            'files'        => 'sometimes|array',
            'files.*'      => 'file|max:10240',
            'mentioned_user_ids' => 'nullable|array',
            'mentioned_user_ids.*' => 'integer|exists:users,id',
        ]);

        // если reply_to_id передали — он должен принадлежать этому же чату
        if (!empty($data['reply_to_id'])) {
            $belongs = ChatMessage::where('id', $data['reply_to_id'])
                ->where('chat_id', $chat->id)
                ->exists();
            abort_unless($belongs, 422, 'reply_to_id does not belong to this chat');
        }

        $msg = ChatMessage::create([
            'chat_id'     => $chat->id,
            'user_id'     => $req->user()->id,
            'content'     => $data['content'] ?? null,
            'kind'        => $data['kind'] ?? 'text',
            'reply_to_id' => $data['reply_to_id'] ?? null,
            'meta'        => $data['meta'] ?? [],
            'mentioned_user_ids' => $data['mentioned_user_ids'] ?? [],
        ]);

        // Сохраняем файлы (если есть)
        if ($req->hasFile('files')) {
            foreach ($req->file('files') as $file) {
                $path = $file->store("chat_attachments/{$chat->id}", 'public');

                $msg->attachments()->create([
                    'uploaded_by'   => $req->user()->id,
                    'disk'          => 'public',
                    'path'          => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime'          => $file->getClientMimeType(),
                    'size'          => $file->getSize(),
                    'sha256'     => hash_file('sha256', $file->getRealPath()),
                    'meta'          => null,
                ]);
            }
        }

        $msg->load([
            'user:id,name,email,avatar',
            'replyTo:id,chat_id,user_id,content',
            'replyTo.user:id,name,email',
            'attachments:id,attachable_id,attachable_type,original_name,mime,size,disk,path,meta,created_at',
        ]);

        // Обновляем last_message_id чата
        $chat->update(['last_message_id' => $msg->id]);

        // получатели: все участники чата, кроме автора
        $recipientIds = ChatParticipant::query()
            ->where('chat_id', $chat->id)
            ->where('user_id', '<>', $req->user()->id)
            ->pluck('user_id')
            ->all();

        // упомянутые: только те, кто реально получатель (участник и не автор)
        $mentionedIds = array_values(array_unique(array_map('intval', $data['mentioned_user_ids'] ?? [])));
        if (!empty($mentionedIds)) {
            $mentionedIds = array_values(array_intersect($mentionedIds, $recipientIds));
        }

        if (!empty($recipientIds)) {
            $settingsByUser = NotificationSetting::query()
                ->whereIn('user_id', $recipientIds)
                ->get()
                ->keyBy('user_id');

            $recipients = User::query()
                ->whereIn('id', $recipientIds)
                ->get();

            foreach ($recipients as $recipient) {
                $st = $settingsByUser->get($recipient->id);

                $inappChatEnabled     = (bool)($st?->inapp_chat_messages);
                $inappMentionsEnabled = (bool)($st?->inapp_mentions);
                $emailChatEnabled     = (bool)($st?->email_chat_messages);

                $isMentioned = in_array((int)$recipient->id, $mentionedIds, true);

                // In-app: упомянутым шлём mention (если включено), иначе обычное "новое сообщение"
                if ($isMentioned && $inappMentionsEnabled) {
                    $recipient->notify(new ChatMentionInAppNotification($chat, $msg));
                } elseif ($inappChatEnabled) {
                    $recipient->notify(new ChatMessageInAppNotification($chat, $msg));
                }

                // Email: только общий переключатель "Новые сообщения в чате"
                if ($emailChatEnabled) {
                    $recipient->notify(new ChatMessageEmailNotification($chat, $msg));
                }
            }
        }

        // Broadcast события всем участникам чата
        try {
            broadcast(new MessageCreated($msg, $chat))->toOthers();
        } catch (BroadcastException $e) {
            // сокеты недоступны/не настроены — просто логируем и продолжаем
            Log::warning('Broadcast failed: '.$e->getMessage());
        } catch (\Throwable $e) {
            // на всякий случай не даём упасть 500-кой
            Log::error('Broadcast unexpected error: '.$e->getMessage());
        }

        return response()->json($msg, 201);
    }

    // опционально: выставить «прочитано» до конкретного сообщения
    public function markRead(Request $req, Chat $chat)
    {
        $this->authorizeMember($req->user()->id, $chat->id);

        $data = $req->validate([
            'last_read_message_id' => 'required|integer|exists:chat_messages,id',
        ]);

        // (опционально) убедимся, что этот message_id из того же чата
        $sameChat = ChatMessage::where('id', $data['last_read_message_id'])
            ->where('chat_id', $chat->id)
            ->exists();
        abort_unless($sameChat, 422, 'Message does not belong to this chat');

        ChatParticipant::where('chat_id', $chat->id)
            ->where('user_id', $req->user()->id)
            ->update(['last_read_message_id' => $data['last_read_message_id']]);

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $req, Chat $chat, ChatMessage $message)
    {
         // Проверяем, что пользователь вообще участник
        $this->authorizeMember($req->user()->id, $chat->id);

        // Проверяем, что сообщение принадлежит этому чату
        abort_unless(
            (int)$message->chat_id === (int)$chat->id,
            404,
            'Message not found in this chat'
        );

        // Определяем роль участника
        $participant = ChatParticipant::where('chat_id', $chat->id)
            ->where('user_id', $req->user()->id)
            ->first(['role']);

        // Право на удаление:
        // свои сообщения — всегда;
        // чужие — только если владелец
        $isAuthor = (int)$message->user_id === (int)$req->user()->id;
        $isOwner  = $participant && $participant->role === 'owner';

        abort_unless(
            $isAuthor || $isOwner,
            403,
            'You cannot delete this message'
        );

        // Удаляем файлы и сообщение
        $message->load('attachments:id,attachable_id,disk,path');
        foreach ($message->attachments as $att) {
            if ($att->disk && $att->path) {
                try {
                    Storage::disk($att->disk)->delete($att->path);
                } catch (\Throwable $e) {}
            }
            $att->delete();
        }

        $deletedId = $message->id;
        $message->delete();

        if ((int)$chat->last_message_id === (int)$deletedId) {
            $lastId = ChatMessage::where('chat_id', $chat->id)->orderByDesc('id')->value('id');
            $chat->update(['last_message_id' => $lastId]);
        }

        return response()->json(['ok' => true]);
    }

    private function authorizeMember($userId, $chatId)
    {
        abort_unless(
            ChatParticipant::where('chat_id', $chatId)->where('user_id', $userId)->exists(),
            403,
            'Forbidden'
        );
    }
}