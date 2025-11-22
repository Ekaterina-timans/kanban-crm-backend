<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    // ГЛОБАЛЬНЫЙ ПОИСК: чаты по title + сообщения по content
    public function global(Request $req)
    {
        $q = trim((string)$req->query('q', ''));
        $limitChats = (int)($req->query('limit_chats', 10));
        $limitMsgs  = (int)($req->query('limit_messages', 30));

        if ($q === '') {
            return response()->json(['chats' => [], 'messages' => []]);
        }

        $userId = $req->user()->id;

        // только те чаты, в которых состоит пользователь
        $chatIds = ChatParticipant::where('user_id', $userId)->pluck('chat_id');

        // сабселекты для последнего сообщения (текст и время)
        $lastMsgTextSub = ChatMessage::select('content')
            ->whereColumn('chat_id', 'chats.id')
            ->orderByDesc('id')
            ->limit(1);

        $lastMsgAtSub = ChatMessage::select('created_at')
            ->whereColumn('chat_id', 'chats.id')
            ->orderByDesc('id')
            ->limit(1);

        // 1) Совпадения по названию чатов
        $chats = Chat::query()
            ->whereIn('id', $chatIds)
            ->where('title', 'like', "%{$q}%")
            ->select(['id','title','type','avatar'])
            ->selectSub($lastMsgTextSub, 'last_message_text')
            ->selectSub($lastMsgAtSub, 'last_message_at')
            ->orderBy('title')
            ->limit($limitChats)
            ->get();

        // 2) Совпадения по сообщениям
        $messages = ChatMessage::query()
            ->with(['user:id,name,email,avatar'])
            ->whereIn('chat_id', $chatIds)
            ->where('content', 'like', "%{$q}%")
            ->orderByDesc('id')
            ->limit($limitMsgs)
            ->get(['id','chat_id','user_id','content','created_at']);

        return response()->json([
            'chats'    => $chats,
            'messages' => $messages,
        ]);
    }

    // ПОИСК ПО ОДНОМУ ЧАТУ (пагинация курсорами по id)
    public function inChat(Request $req, Chat $chat)
    {
        $this->authorizeMember($req->user()->id, $chat->id);

        $data = $req->validate([
            'q'         => 'required|string|min:1',
            'limit'     => 'sometimes|integer|min:1|max:100',
            'before_id' => 'sometimes|integer|min:1',
            'after_id'  => 'sometimes|integer|min:1',
        ]);

        if (!empty($data['before_id']) && !empty($data['after_id'])) {
            return response()->json(['message' => 'Use either before_id or after_id, not both.'], 422);
        }

        $q = $data['q'];
        $limit = (int)($data['limit'] ?? 30);
        $beforeId = $data['before_id'] ?? null;
        $afterId  = $data['after_id'] ?? null;

        $qr = ChatMessage::query()
            ->with(['user:id,name,email,avatar'])
            ->where('chat_id', $chat->id)
            ->where('content', 'like', "%{$q}%");

        if ($beforeId && !$afterId) {
            $qr->where('id', '<', $beforeId)->orderByDesc('id');
        } elseif ($afterId && !$beforeId) {
            $qr->where('id', '>', $afterId)->orderBy('id');
        } else {
            $qr->orderByDesc('id');
        }

        $rows = $qr->limit($limit)->get();
        $items = $rows->sortBy('id')->values();

        $oldestId = $items->first()->id ?? null;
        $newestId = $items->last()->id ?? null;

        $hasOlder = $oldestId
            ? (bool) ChatMessage::where('chat_id', $chat->id)
                ->where('content', 'like', "%{$q}%")
                ->where('id', '<', $oldestId)
                ->value('id')
            : false;

        $hasNewer = $newestId
            ? (bool) ChatMessage::where('chat_id', $chat->id)
                ->where('content', 'like', "%{$q}%")
                ->where('id', '>', $newestId)
                ->value('id')
            : false;

        return response()->json([
            'query'    => $q,
            'messages' => $items,
            'cursors'  => [
                'oldest_id' => $oldestId,
                'newest_id' => $newestId,
                'has_older' => $hasOlder,
                'has_newer' => $hasNewer,
            ],
        ]);
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
