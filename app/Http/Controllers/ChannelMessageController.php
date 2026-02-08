<?php

namespace App\Http\Controllers;

use App\Models\ChannelMessage;
use App\Models\ChannelThread;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ChannelMessageController extends Controller
{
    public function index(Request $request, ChannelThread $thread)
    {
        $channel = $thread->channel;
        $group = $channel->group;

        // доступ: пользователь должен быть в группе
        abort_unless(
            $group->users()->whereKey($request->user()->id)->exists(),
            403,
            'Forbidden'
        );

        $perPage = (int)$request->query('per_page', 50);
        $perPage = max(1, min($perPage, 200));

        // можно фильтровать по direction=in|out (опционально)
        $direction = $request->query('direction');
        if ($direction !== null) {
            abort_unless(in_array($direction, ['in', 'out'], true), 422, 'Invalid direction');
        }

        $query = $thread->messages()
            ->select([
                'id',
                'channel_thread_id',
                'direction',
                'external_message_id',
                'external_update_id',
                'sender_external_id',
                'text',
                'provider_date',
                'created_at',
            ])
            ->orderByDesc('id');

        if ($direction !== null) {
            $query->where('direction', $direction);
        }

        // вернем последние сообщения, фронт может отрисовать "снизу вверх"
        $page = $query->paginate($perPage);

        return response()->json($page);
    }

    public function send(Request $request, ChannelThread $thread)
    {
        $channel = $thread->channel;
        $group = $channel->group;

        abort_unless(
            $group->users()->whereKey($request->user()->id)->exists(),
            403,
            'Forbidden'
        );

        abort_unless($channel->provider === 'telegram', 422, 'Thread is not telegram provider');
        abort_unless($channel->status === 'active', 422, 'Channel is not active');

        $data = $request->validate([
            'text' => 'required|string|min:1|max:4000',
        ]);

        $token = (string) config('services.telegram.bot_token');
        abort_unless($token !== '', 500, 'Telegram bot token is not configured');

        $chatId = $thread->external_chat_id;

        $resp = Http::timeout(15)->post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text'    => $data['text'],
        ]);

        abort_unless($resp->ok(), 422, 'Telegram sendMessage failed');

        $json = $resp->json();
        abort_unless(($json['ok'] ?? false) === true, 422, 'Telegram sendMessage ok=false');

        $msg = $json['result'] ?? [];

        // сохраняем OUT
        $saved = ChannelMessage::create([
            'channel_thread_id'   => $thread->id,
            'direction'           => 'out',
            'external_message_id' => isset($msg['message_id']) ? (string)$msg['message_id'] : null,
            'external_update_id'  => null,
            'sender_external_id'  => null,
            'text'                => $msg['text'] ?? $data['text'],
            'payload'             => $msg,
            'provider_date'       => isset($msg['date']) ? (int)$msg['date'] : null,
        ]);

        return response()->json([
            'ok' => true,
            'message' => $saved,
        ], 201);
    }
}
