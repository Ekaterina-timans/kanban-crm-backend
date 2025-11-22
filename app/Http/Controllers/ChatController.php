<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\Group;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    // чаты по группе текущего пользователя
    public function index(Request $req, Group $group)
    {
        $meId = $req->user()->id;

        // авторизация: пользователь должен состоять в группе
        abort_unless(
            $group->users()->where('users.id', $meId)->exists(),
            403, 'Forbidden'
        );

        // Подзапрос непрочитанных (через Eloquent)
        $unreadSub = ChatMessage::query()
            ->selectRaw('COUNT(*)')
            ->whereColumn('chat_messages.chat_id', 'chats.id')
            ->where('chat_messages.user_id', '<>', $meId)
            ->whereRaw('chat_messages.id > COALESCE((
                SELECT last_read_message_id FROM chat_participants
                WHERE chat_participants.chat_id = chats.id
                AND chat_participants.user_id = ?
            ), 0)', [$meId]);

        $chats = Chat::query()
            ->with([
                'group:id,name',
                'participants.user:id,name,email,avatar',
                'lastMessage.user:id,name,avatar',
            ])
            ->withCount('participants')
            ->where('group_id', $group->id)
            ->whereHas('participants', fn($q) => $q->where('user_id', $meId))
            ->select('chats.*')
            ->selectSub($unreadSub, 'unread_count')
            ->orderByDesc('updated_at')
            ->get()
            ->map(function (Chat $chat) use ($meId) {
                // Нормализуем заголовок/аватар для фронта
                if ($chat->type === 'direct') {
                    $otherUser = $chat->participants->firstWhere('user_id', '!=', $meId)?->user;
                    $title = $chat->title ?: ($otherUser?->name ?? $otherUser?->email ?? 'Личный чат');
                    $avatarUrl = $otherUser?->avatar
                        ? asset('storage/'.$otherUser->avatar)
                        : null;
                } else {
                    $title = $chat->title ?: ($chat->group?->name ?? 'Беседа');
                    $avatarUrl = $chat->avatar_url;
                }

                $preview = null;
                if ($chat->lastMessage) {
                    $m = $chat->lastMessage;
                    $preview = $this->buildLastMessagePreview($m);
                }

                return [
                    'id'            => $chat->id,
                    'type'          => $chat->type,
                    'title'         => $title,
                    'avatar'        => $avatarUrl,
                    'unread_count'  => (int)($chat->unread_count ?? 0),
                    'last_message_id'=> $chat->last_message_id,
                    'last_message'    => $preview,
                    'participants_count' => $chat->participants_count,
                    'updated_at'    => $chat->updated_at,
                ];
            });

        return response()->json($chats);
    }

    public function store(Request $req, Group $group)
    {
        $me = $req->user();

        $data = $req->validate([
            'type'         => 'required|in:group,direct',
            'title'        => 'nullable|string|max:120',
            'participants' => 'array',
            'participants.*' => 'integer|exists:users,id',
        ]);

        // 1) авторизация: я должен быть в группе
        abort_unless(
            $group->users()->where('users.id', $me->id)->exists(),
            403, 'Forbidden'
        );

        // 2) валидация по типу
        if ($data['type'] === 'direct') {
            // Должно быть ровно 1 ID, не равный мне
            $ids = array_values(array_unique($data['participants'] ?? []));
            abort_if(count($ids) !== 1 || (int)$ids[0] === (int)$me->id, 422, 'Укажи ровно одного собеседника');

            // Собеседник должен состоять в этой же группе
            abort_unless(
                $group->users()->where('users.id', $ids[0])->exists(),
                422, 'Пользователь не состоит в группе'
            );

            // (Опционально) не создавать дубликат direct-чата с этим пользователем
            $already = Chat::where('group_id', $group->id)
                ->where('type', 'direct')
                ->whereHas('participants', fn($q)=>$q->where('user_id',$me->id))
                ->whereHas('participants', fn($q)=>$q->where('user_id',$ids[0]))
                ->first();

            if ($already) {
                return response()->json($already->load('participants.user'), 200);
            }
        } else {
            // group: минимум 2 уникальных участника (включая меня) — значит в массиве должно быть >=1 и не я
            $ids = array_values(array_unique(array_filter(
                $data['participants'] ?? [],
                fn($id) => (int)$id !== (int)$me->id
            )));
            abort_if(count($ids) < 1, 422, 'Добавь хотя бы одного участника');
            // все должны быть членами группы
            $countInGroup = $group->users()->whereIn('users.id', $ids)->count();
            abort_unless($countInGroup === count($ids), 422, 'Часть пользователей не состоит в группе');
        }

        // 3) создаём чат
        $chat = Chat::create([
            'group_id'   => $group->id,
            'created_by' => $me->id,
            'title'      => $data['type'] === 'group' ? ($data['title'] ?? null) : null,
            'type'       => $data['type'],
        ]);

        // 4) добавляем создателя
        ChatParticipant::create([
            'chat_id' => $chat->id,
            'user_id' => $me->id,
            'role'    => 'owner',
        ]);

        // 5) добавляем остальных
        if ($data['type'] === 'direct') {
            ChatParticipant::firstOrCreate([
                'chat_id' => $chat->id,
                'user_id' => $ids[0],
            ], ['role' => 'member']);
        } else {
            foreach ($ids as $uid) {
                ChatParticipant::firstOrCreate(
                    ['chat_id' => $chat->id, 'user_id' => $uid],
                    ['role' => 'member']
                );
            }
        }

        return response()->json($chat->load('participants.user'), 201);
    }

    public function show(Request $req, Chat $chat)
    {
        $meId = $req->user()->id;

        abort_unless(
            $chat->participants()->where('user_id', $meId)->exists(),
            403, 'Forbidden'
        );

        $chat->load(['group:id,name','participants.user:id,name,email,avatar'])
            ->loadCount('participants');

        // Нормализуем только то, что нужно для шапки
        if ($chat->type === 'direct') {
            $other = $chat->participants->firstWhere('user_id', '!=', $meId)?->user;
            $title  = $chat->title ?: ($other?->name ?? $other?->email ?? 'Личный чат');
            $avatar = $other?->avatar ? asset('storage/'.$other->avatar) : null;
            $participantsCount = null; // для direct не показываем
        } else {
            $title  = $chat->title ?: ($chat->group?->name ?? 'Беседа');
            $avatar = $chat->avatar ? asset('storage/'.$chat->avatar) : ($chat->avatar_url ?? null);
            $participantsCount = $chat->participants_count;
        }

        return response()->json([
            'id'                 => $chat->id,
            'type'               => $chat->type,
            'title'              => $title,
            'avatar'             => $avatar,
            'participants_count' => $participantsCount,
            'updated_at'         => $chat->updated_at,
        ]);
    }

    public function updateAvatar(Request $req, Chat $chat)
    {
        // авторизация: должен быть участником (или админом группы/владелец чата)
        $participant = ChatParticipant::where('chat_id', $chat->id)
            ->where('user_id', $req->user()->id)
            ->firstOrFail();

        abort_unless(
            in_array($participant->role, ['owner', 'admin'], true),
            403,
            'Недостаточно прав для изменения аватара чата'
        );

        $data = $req->validate([
            'avatar' => 'required|image|max:5120', // до 5MB
        ]);

        $file = $data['avatar'];
        // путь вида: public/chat_avatars/{chat_id}/<uniq>.png
        $path = $file->store("chat_avatars/{$chat->id}", 'public');

        if ($chat->avatar && Storage::disk('public')->exists($chat->avatar)) {
            Storage::disk('public')->delete($chat->avatar);
        }

        $chat->avatar = $path;
        $chat->save();

        return response()->json([
            'id'         => $chat->id,
            'avatar'     => $chat->avatar ? asset('storage/'.$chat->avatar) : null,
            'message'    => 'Avatar updated',
        ]);
    }

    /**
     * Короткое превью последнего сообщения для списка чатов.
     */
    protected function buildLastMessagePreview(ChatMessage $m): string
    {
        // Если системное — показываем короткий текст
        if ($m->kind === 'system') {
            $text = $m->meta['text'] ?? 'Системное сообщение';
            return Str::limit($text, 80);
        }

        // Если опрос — тоже какой-то текст
        if ($m->kind === 'poll') {
            return 'Опрос';
        }

        $text = trim((string) $m->content);

        if ($text === '') {
            $text = 'Вложение';
        }

        return Str::limit($text, 80);
    }

    /**
     * Удаление чата целиком.
     * Право: владелец (role = owner) или создатель чата.
     */
    public function destroy(Request $req, Chat $chat)
    {
        $meId = $req->user()->id;

        abort_unless(
            $chat->participants()->where('user_id', $meId)->exists(),
            403, 'Forbidden'
        );

        // только владелец или создатель
        $isOwner = $chat->participants()
            ->where('user_id', $meId)
            ->whereIn('role', ['owner'])
            ->exists();

        abort_unless($isOwner || (int)$chat->created_by === (int)$meId, 403, 'Only owner can delete chat');

        DB::transaction(function () use ($chat) {
            // удалить файл аватара, если был
            if ($chat->avatar && Storage::disk('public')->exists($chat->avatar)) {
                Storage::disk('public')->delete($chat->avatar);
            }

            // при корректных FK (cascadeOnDelete) этого достаточно:
            $chat->delete();

            // если FK не везде с каскадом — раскомментируй:
            // ChatMessage::where('chat_id', $chat->id)->delete();
            // ChatParticipant::where('chat_id', $chat->id)->delete();
            // $chat->delete();
        });

        return response()->json(['ok' => true]);
    }

    /**
     * Очистка истории: удалить все сообщения в чате, оставить чат.
     */
    public function clearHistory(Request $req, Chat $chat)
    {
        $meId = $req->user()->id;

        abort_unless(
            $chat->participants()->where('user_id', $meId)->exists(),
            403, 'Forbidden'
        );

        $lastMsgId = $chat->messages()->latest('id')->value('id');

        ChatParticipant::where('chat_id', $chat->id)
            ->where('user_id', $meId)
            ->update(['last_read_message_id' => $lastMsgId]);

        return response()->json(['ok' => true, 'scope' => 'self']);
    }

    /**
     * Выйти из чата
     */
    public function leaveChat(Request $req, Chat $chat)
    {
        $meId = $req->user()->id;

        $participant = ChatParticipant::where('chat_id', $chat->id)
            ->where('user_id', $meId)
            ->firstOrFail();

        if ($participant->role === 'owner') {
            $ownersCount = ChatParticipant::where('chat_id', $chat->id)
                ->where('role', 'owner')
                ->count();
            abort_if($ownersCount <= 1, 422, 'Вы единственный владелец и не можете выйти.');
        }

        $participant->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Участники чата (для group и direct).
     */
    public function participants(Request $req, Chat $chat)
    {
        $meId = $req->user()->id;

        abort_unless(
            $chat->participants()->where('user_id', $meId)->exists(),
            403, 'Forbidden'
        );

        $q = ChatParticipant::query()
            ->with(['user:id,name,email,avatar'])
            ->where('chat_id', $chat->id)
            ->addSelect([
                'chat_participants.*',
                'sort_key' => User::select(DB::raw("LOWER(COALESCE(name, email))"))
                    ->whereColumn('users.id', 'chat_participants.user_id')
                    ->limit(1),
            ])
            ->orderByRaw("CASE role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END")
            ->orderBy('sort_key');

        $parts = $q->get();

        $myRole = optional($parts->firstWhere('user_id', $meId))->role ?? 'member';

        return response()->json([
            'my_role'          => $myRole,
            'participants'     => $parts->map(fn($p) => [
                'id'     => (int) $p->user_id,
                'name'   => $p->user?->name,
                'email'  => $p->user?->email,
                'avatar' => $p->user?->avatar ? asset('storage/'.$p->user->avatar) : null,
                'role'   => $p->role,
            ])->values(),
        ]);
    }

    public function addParticipants(Request $req, Chat $chat)
    {
       $meId = $req->user()->id;

        abort_unless(
            ChatParticipant::where('chat_id', $chat->id)->where('user_id', $meId)->exists(),
            403, 'Forbidden'
        );

        abort_if($chat->type === 'direct', 422, 'Нельзя добавлять участников в личный чат');

        // мои права
        $myRole = ChatParticipant::where('chat_id', $chat->id)
            ->where('user_id', $meId)
            ->value('role');

        $participant = ChatParticipant::where('chat_id', $chat->id)
            ->where('user_id', $meId)
            ->firstOrFail();

        abort_unless($participant->canAddParticipants(), 403, 'Недостаточно прав');

        // валидация: принимаем ИЛИ emails, ИЛИ user_ids (оба можно, объединим)
        $data = $req->validate([
            'emails'     => 'sometimes|array|min:1',
            'emails.*'   => 'required_with:emails|email',
            'user_ids'   => 'sometimes|array|min:1',
            'user_ids.*' => 'required_with:user_ids|integer|exists:users,id',
            'role'       => 'sometimes|in:member,admin,owner',
        ]);

        // собираем пользователей из emails
        $usersByEmail = collect();
        if (!empty($data['emails'])) {
            $usersByEmail = User::query()
                ->whereIn('email', $data['emails'])
                ->get(['id','name','email','avatar']);
        }

        // и из id
        $usersById = collect();
        if (!empty($data['user_ids'])) {
            $usersById = User::query()
                ->whereIn('id', $data['user_ids'])
                ->get(['id','name','email','avatar']);
        }

        // объединяем (по id) и фильтруем: не добавляем самого себя
        $users = $usersByEmail->concat($usersById)
            ->unique('id')
            ->reject(fn($u) => (int)$u->id === (int)$meId)
            ->values();

        abort_if($users->isEmpty(), 422, 'Список пользователей пуст');

        // проверим, что все в той же группе
        $groupUserIds = $chat->group ? $chat->group->users()->pluck('users.id')->all() : [];
        $users = $users->filter(fn($u) => in_array($u->id, $groupUserIds, true))->values();
        abort_if($users->isEmpty(), 422, 'Часть (или все) пользователей не состоят в этой группе');

        // роль, которую назначаем
        $requestedRole = $data['role'] ?? 'member';
        // админ не может назначать admin/owner — только member
        if ($myRole === 'admin') {
            $requestedRole = 'member';
        }
        // дополнительная страховка: только owner может назначать не-member
        if ($myRole !== 'owner' && $requestedRole !== 'member') {
            $requestedRole = 'member';
        }

        // создать участников (игнорируем уже существующих)
        DB::transaction(function () use ($chat, $users, $requestedRole) {
            foreach ($users as $u) {
                ChatParticipant::firstOrCreate(
                    ['chat_id' => $chat->id, 'user_id' => $u->id],
                    ['role' => $requestedRole]
                );
            }
        });

        $parts = ChatParticipant::query()
            ->with(['user:id,name,email,avatar'])
            ->where('chat_id', $chat->id)
            ->addSelect([
                'chat_participants.*',
                'sort_key' => \App\Models\User::select(DB::raw("LOWER(COALESCE(name, email))"))
                    ->whereColumn('users.id', 'chat_participants.user_id')
                    ->limit(1),
            ])
            ->orderByRaw("CASE role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END")
            ->orderBy('sort_key')
            ->get();

        return response()->json([
            'ok' => true,
            'participants' => $parts->map(fn($p) => [
                'id'     => (int) $p->user_id,
                'name'   => $p->user?->name,
                'email'  => $p->user?->email,
                'avatar' => $p->user?->avatar ? asset('storage/'.$p->user->avatar) : null,
                'role'   => $p->role,
            ])->values(),
        ]);
    }

    /**
     * PATCH /chats/{chat}/participants/{user}
     * body: { role: 'owner'|'admin'|'member' }
     */
    public function updateParticipantRole(Request $req, Chat $chat, User $user)
    {
       $meId = $req->user()->id;

        // менять роли может только владелец
        $isOwner = ChatParticipant::where('chat_id', $chat->id)
            ->where('user_id', $meId)
            ->where('role', 'owner')
            ->exists();
        abort_unless($isOwner, 403, 'Only owner can change roles');

        $data = $req->validate([
            'role' => 'required|in:owner,admin,member',
        ]);
        $newRole = $data['role'];

        return DB::transaction(function () use ($chat, $user, $meId, $newRole) {
            $target = ChatParticipant::where('chat_id', $chat->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $currentOwner = ChatParticipant::where('chat_id', $chat->id)
                ->where('role', 'owner')
                ->lockForUpdate()
                ->first();

            if ($newRole === 'owner') {
                // передача владения: target -> owner, текущий владелец -> admin
                if ($currentOwner && (int)$currentOwner->user_id !== (int)$target->user_id) {
                    $target->role = 'owner';
                    $target->save();

                    $currentOwner->role = 'admin';
                    $currentOwner->save();
                }
            } else {
                // запретим снимать последнего владельца
                if ($target->role === 'owner' && $newRole !== 'owner') {
                    abort(422, 'Нельзя снять последнего владельца (передайте владельца другому).');
                }
                $target->role = $newRole;
                $target->save();
            }

            return response()->json([
                'ok' => true,
                'participant' => ['id' => (int)$user->id, 'role' => $target->role],
            ]);
        });
    }

    public function removeParticipant(Request $req, Chat $chat, User $user)
    {
        $meId = $req->user()->id;

        abort_unless(
            ChatParticipant::where('chat_id', $chat->id)->where('user_id', $meId)->exists(),
            403, 'Forbidden'
        );

        if ($chat->type === 'direct' && (int)$user->id !== (int)$meId) {
            abort(403, 'Нельзя удалять других участников из личного чата');
        }

        $isOwner = ChatParticipant::where('chat_id', $chat->id)
            ->where('user_id', $meId)
            ->where('role', 'owner')
            ->exists();
        $isSelf = (int)$user->id === (int)$meId;
        abort_unless($isOwner || $isSelf, 403, 'Недостаточно прав');

        return DB::transaction(function () use ($chat, $user) {
            $p = ChatParticipant::where('chat_id', $chat->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($p->role === 'owner') {
                $owners = ChatParticipant::where('chat_id', $chat->id)
                    ->where('role', 'owner')
                    ->lockForUpdate()
                    ->count();

                if ($owners <= 1) {
                    if ($next = $this->pickNextOwner($chat)) {
                        $next->role = 'owner';
                        $next->save();
                    }
                }
            }

            $p->delete();

            return response()->json([
                'ok' => true,
                'removed_user_id' => (int)$user->id,
            ]);
        });
    }

    /**
     * Все вложения чата (по всем сообщениям), с url и download_url.
     */
    public function attachments(Request $req, Chat $chat)
    {
        $meId = $req->user()->id;

        // доступ — только участникам
        abort_unless(
            $chat->participants()->where('user_id', $meId)->exists(),
            403, 'Forbidden'
        );

        // Берём id всех сообщений чата одним запросом
        $messageIds = $chat->messages()->pluck('id');

        // Все Attachment к этим сообщениям
        $rows = Attachment::query()
            ->where('attachable_type', ChatMessage::class)
            ->whereIn('attachable_id', $messageIds)
            ->orderByDesc('id')
            ->get();

        $items = $rows->map(function (Attachment $a) {
            $publicUrl = $a->path ? asset('storage/'.$a->path) : null;

            return [
                'id'            => $a->id,
                'original_name' => $a->original_name,
                'mime'          => $a->mime,
                'size'          => $a->size,
                'url'           => $publicUrl,
                'download_url'  => route('attachments.download', $a),
                'created_at'    => $a->created_at,
            ];
        })->values();

        return response()->json($items);
    }

    protected function pickNextOwner(Chat $chat): ?ChatParticipant
    {
        $cand = ChatParticipant::where('chat_id', $chat->id)
            ->where('role', 'admin')
            ->orderBy('id')
            ->first();

        if ($cand) return $cand;

        return ChatParticipant::where('chat_id', $chat->id)
            ->where('role', 'member')
            ->orderBy('id')
            ->first();
    }

}
