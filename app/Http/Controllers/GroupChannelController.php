<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\GroupChannel;
use Illuminate\Http\Request;

class GroupChannelController extends Controller
{
    /**
     * Пользователь должен быть членом группы.
     */
    private function requireGroupMember(Group $group, int $userId): void
    {
        abort_unless(
            $group->users()->whereKey($userId)->exists(),
            403,
            'Forbidden'
        );
    }

    /**
     * Только админ группы.
     */
    private function requireGroupAdmin(Group $group, int $userId): void
    {
        $member = $group->users()->whereKey($userId)->first();
        abort_unless(
            $member && $member->pivot->role === 'admin',
            403,
            'Only admin can manage channels'
        );
    }

    /**
     * Защита: channel должен принадлежать group.
     */
    private function requireChannelInGroup(Group $group, GroupChannel $channel): void
    {
        abort_unless(
            (int)$channel->group_id === (int)$group->id,
            404,
            'Channel not found in this group'
        );
    }

    public function index(Request $request, Group $group)
    {
        $this->requireGroupMember($group, (int)$request->user()->id);

        return response()->json(
            $group->channels()->orderBy('id')->get()
        );
    }

    public function store(Request $request, Group $group)
    {
        $this->requireGroupAdmin($group, (int)$request->user()->id);

        $data = $request->validate([
            'provider'     => 'required|string|max:32',
            'display_name' => 'required|string|max:120',
        ]);

        $channel = GroupChannel::create([
            'group_id'     => $group->id,
            'provider'     => strtolower(trim($data['provider'])),
            'display_name' => trim($data['display_name']),
            'status'       => 'disabled',
            'settings'     => null,
            'secrets'      => null,
        ]);

        return response()->json($channel, 201);
    }

    public function update(Request $request, Group $group, GroupChannel $channel)
    {
        $this->requireGroupAdmin($group, (int)$request->user()->id);
        $this->requireChannelInGroup($group, $channel);

        $data = $request->validate([
            'display_name' => 'sometimes|required|string|max:120',
            'status'       => 'sometimes|required|in:active,disabled,error',
            'settings'     => 'sometimes|nullable|array',
        ]);

        // secrets здесь не обновляем (для токенов будет отдельный endpoint позже)
        $channel->fill($data);
        $channel->save();

        return response()->json([
            'message' => 'Channel updated',
            'channel' => $channel->fresh(),
        ]);
    }

    public function destroy(Request $request, Group $group, GroupChannel $channel)
    {
        $this->requireGroupAdmin($group, (int)$request->user()->id);
        $this->requireChannelInGroup($group, $channel);

        $channel->delete();

        return response()->json(['ok' => true]);
    }

    public function threadsIndex(Request $request, Group $group, GroupChannel $channel)
    {
        $this->requireGroupMember($group, (int)$request->user()->id);
        $this->requireChannelInGroup($group, $channel);

        $q = trim((string)$request->query('q', ''));
        $perPage = (int)$request->query('per_page', 30);
        $perPage = max(1, min($perPage, 100));

        $query = $channel->threads()
            ->select([
                'id',
                'group_channel_id',
                'external_chat_id',
                'external_peer_id',
                'thread_type',
                'title',
                'username',
                'first_name',
                'last_name',
                'last_update_id',
                'last_message_text',
                'last_message_at',
                'updated_at',
                'created_at',
            ])
            ->orderByDesc('updated_at');

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('title', 'like', "%{$q}%")
                ->orWhere('username', 'like', "%{$q}%")
                ->orWhere('first_name', 'like', "%{$q}%")
                ->orWhere('last_name', 'like', "%{$q}%")
                ->orWhere('external_chat_id', 'like', "%{$q}%");
            });
        }

        return response()->json($query->paginate($perPage));
    }

    public function connectTelegram(Request $request, Group $group, GroupChannel $channel)
    {
        $this->requireGroupAdmin($group, (int)$request->user()->id);
        $this->requireChannelInGroup($group, $channel);

        abort_unless($channel->provider === 'telegram', 422, 'This channel is not telegram provider');

        $data = $request->validate([
            'bot_token' => 'required|string|min:20|max:255',
        ]);

        $token = trim($data['bot_token']);

        // Проверяем токен через getMe
        $resp = \Illuminate\Support\Facades\Http::timeout(10)
            ->get("https://api.telegram.org/bot{$token}/getMe");

        abort_unless($resp->ok(), 422, 'Telegram getMe failed');

        $json = $resp->json();
        abort_unless(($json['ok'] ?? false) === true, 422, 'Telegram token is invalid');

        $bot = $json['result'] ?? [];

        // сохраняем токен в secrets
        $secrets = $channel->secrets ?? [];
        $secrets['telegram'] = array_merge($secrets['telegram'] ?? [], [
            'bot_token' => $token,
        ]);
        $channel->secrets = $secrets;

        // settings: инфо о боте
        $settings = $channel->settings ?? [];
        $settings['telegram'] = array_merge($settings['telegram'] ?? [], [
            'bot_username' => $bot['username'] ?? null,
            'bot_name' => $bot['first_name'] ?? null,
            'bot_id' => $bot['id'] ?? null,
            'last_update_id' => null,
        ]);

        $channel->settings = $settings;
        $channel->status = 'active';
        $channel->save();

        return response()->json([
            'ok' => true,
            'channel' => $channel->fresh(),
        ]);
    }

    public function disconnectTelegram(Request $request, Group $group, GroupChannel $channel)
    {
        $this->requireGroupAdmin($group, (int)$request->user()->id);
        $this->requireChannelInGroup($group, $channel);

        abort_unless($channel->provider === 'telegram', 422, 'This channel is not telegram provider');

        // Стираем токен
        $secrets = $channel->secrets ?? [];
        if (isset($secrets['telegram'])) {
            unset($secrets['telegram']['bot_token']);
            // если вдруг telegram пустой — можно целиком убрать
            if (empty($secrets['telegram'])) {
                unset($secrets['telegram']);
            }
        }
        $channel->secrets = $secrets;

        // чистим telegram settings, чтобы UI показывал "не подключено"
        $settings = $channel->settings ?? [];
        $settings['telegram'] = array_merge($settings['telegram'] ?? [], [
            'bot_username'   => null,
            'bot_name'       => null,
            'bot_id'         => null,
            'last_update_id' => null,
        ]);
        $channel->settings = $settings;

        $channel->status = 'disabled';
        $channel->save();

        return response()->json([
            'ok' => true,
            'channel' => $channel->fresh(),
        ]);
    }
}
