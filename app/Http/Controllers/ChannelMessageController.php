<?php

namespace App\Http\Controllers;

use App\Jobs\SendTelegramFileJob;
use App\Models\ChannelMessage;
use App\Models\ChannelThread;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChannelMessageController extends Controller
{
    public function index(Request $request, ChannelThread $thread)
    {
        $channel = $thread->channel;
        $group = $channel->group;

        abort_unless(
            $group->users()->whereKey($request->user()->id)->exists(),
            403,
            'Forbidden'
        );

        $perPage = (int)$request->query('per_page', 50);
        $perPage = max(1, min($perPage, 200));

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
                'payload',
                'provider_date',
                'created_at',
            ])
            ->orderByDesc('id');

        if ($direction !== null) {
            $query->where('direction', $direction);
        }

        return response()->json($query->paginate($perPage));
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

        // принимаем либо текст, либо файл, либо оба
        $data = $request->validate([
            'text' => 'sometimes|nullable|string|max:4000',
            'file' => 'sometimes|nullable|file|max:20480', // 20MB
        ]);

        $text = trim((string)($data['text'] ?? ''));
        /** @var UploadedFile|null $file */
        $file = $request->file('file');

        abort_unless($text !== '' || $file !== null, 422, 'Text or file is required');

        $token = (string) data_get($channel->secrets, 'telegram.bot_token', '');
        abort_unless($token !== '', 500, 'Telegram bot token is not configured for this group channel');

        $chatId = (string)$thread->external_chat_id;

        // Общая HTTP конфигурация (для текста)
        // Важно: на Windows/OpenServer часто проблемы с IPv6 => принудим IPv4.
        $http = Http::connectTimeout(20)
            ->timeout(35)
            ->retry(2, 700)
            ->withOptions([
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                ],
            ]);

        // -----------------------------
        // CASE A: только текст (синхронно)
        // -----------------------------
        if ($file === null) {
            $endpoint = "https://api.telegram.org/bot{$token}/sendMessage";

            $t0 = microtime(true);
            try {
                $resp = $http->post($endpoint, [
                    'chat_id' => $chatId,
                    'text'    => $text,
                ]);
            } catch (ConnectionException $e) {
                Log::error('Telegram sendMessage connection failed', [
                    'thread_id' => $thread->id,
                    'group_channel_id' => $channel->id,
                    'endpoint' => $endpoint,
                    'elapsed_ms' => (int) round((microtime(true) - $t0) * 1000),
                    'message' => $e->getMessage(),
                ]);
                abort(422, 'Telegram sendMessage connection failed');
            } catch (\Throwable $e) {
                Log::error('Telegram sendMessage unexpected error', [
                    'thread_id' => $thread->id,
                    'group_channel_id' => $channel->id,
                    'endpoint' => $endpoint,
                    'elapsed_ms' => (int) round((microtime(true) - $t0) * 1000),
                    'message' => $e->getMessage(),
                ]);
                abort(422, 'Telegram sendMessage unexpected error');
            }

            if ($resp->status() === 401) {
                $channel->status = 'error';
                $channel->save();
                abort(422, 'Telegram bot token unauthorized. Reconnect integration.');
            }

            if (!$resp->ok()) {
                Log::warning('Telegram sendMessage failed http', [
                    'thread_id' => $thread->id,
                    'group_channel_id' => $channel->id,
                    'endpoint' => $endpoint,
                    'status' => $resp->status(),
                    'elapsed_ms' => (int) round((microtime(true) - $t0) * 1000),
                    'body' => $resp->body(),
                ]);
                abort(422, 'Telegram sendMessage failed');
            }

            $json = $resp->json();
            if (!(($json['ok'] ?? false) === true)) {
                Log::warning('Telegram sendMessage ok=false', [
                    'thread_id' => $thread->id,
                    'group_channel_id' => $channel->id,
                    'endpoint' => $endpoint,
                    'elapsed_ms' => (int) round((microtime(true) - $t0) * 1000),
                    'json' => $json,
                ]);
                abort(422, 'Telegram sendMessage ok=false');
            }

            $msg = $json['result'] ?? [];

            $saved = ChannelMessage::create([
                'channel_thread_id'   => $thread->id,
                'direction'           => 'out',
                'external_message_id' => isset($msg['message_id']) ? (string)$msg['message_id'] : null,
                'external_update_id'  => null,
                'sender_external_id'  => null,
                'text'                => $msg['text'] ?? $text,
                'payload'             => $msg,
                'provider_date'       => isset($msg['date']) ? (int)$msg['date'] : null,
            ]);

            $this->touchThreadLastMessage($thread, $saved);

            return response()->json([
                'ok' => true,
                'message' => $saved,
            ], 201);
        }

        // -----------------------------
        // CASE B: файл (через очередь)
        // -----------------------------
        $mime = (string)($file->getMimeType() ?? '');
        $originalName = $file->getClientOriginalName() ?: 'file';
        $size = (int)($file->getSize() ?? 0);

        // caption в Telegram ограничен ~1024
        $caption = $text !== '' ? mb_substr($text, 0, 1024) : null;

        // 1) Сохраняем файл локально (storage/app/telegram_uploads)
        $ext = pathinfo($originalName, PATHINFO_EXTENSION);
        $safeExt = $ext ? ('.' . $ext) : '';
        $storedName = 'tg_' . now()->format('Ymd_His') . '_' . bin2hex(random_bytes(6)) . $safeExt;

        $disk = 'local';
        $dir = 'telegram_uploads';

        try {
            $path = $file->storeAs($dir, $storedName, $disk); // относительный путь внутри storage/app
        } catch (\Throwable $e) {
            Log::error('Cannot store telegram upload file', [
                'thread_id' => $thread->id,
                'group_channel_id' => $channel->id,
                'name' => $originalName,
                'mime' => $mime,
                'size' => $size,
                'message' => $e->getMessage(),
            ]);
            abort(422, 'Cannot store uploaded file');
        }

        // 2) Создаём placeholder-сообщение (UI увидит сразу)
        $queuedPayload = [
            'queued' => true,
            'queued_at' => now()->toISOString(),
            'upload' => [
                'disk' => $disk,
                'path' => $path,
                'original_name' => $originalName,
                'mime' => $mime,
                'size' => $size,
            ],
        ];

        $saved = ChannelMessage::create([
            'channel_thread_id'   => $thread->id,
            'direction'           => 'out',
            'external_message_id' => null,
            'external_update_id'  => null,
            'sender_external_id'  => null,
            'text'                => $caption ?? null,
            'payload'             => $queuedPayload,
            'provider_date'       => null,
        ]);

        $this->touchThreadLastMessage($thread, $saved);

        // 3) Диспатчим job
        // ВАЖНО: твой SendTelegramFileJob::__construct принимает 6 параметров:
        // (threadId, messageId, localPath, originalName, caption, mime)
        try {
            SendTelegramFileJob::dispatch(
                $thread->id,
                $saved->id,
                storage_path('app/' . $path), // абсолютный путь для is_file()
                $originalName,
                $caption,
                $mime
            );
        } catch (\Throwable $e) {
            Log::error('Cannot dispatch SendTelegramFileJob', [
                'thread_id' => $thread->id,
                'group_channel_id' => $channel->id,
                'message_id' => $saved->id,
                'disk' => $disk,
                'path' => $path,
                'message' => $e->getMessage(),
            ]);

            // отметим в payload ошибку (миграции не нужны)
            $saved->payload = array_merge($queuedPayload, [
                'queued' => false,
                'error' => 'dispatch_failed',
            ]);
            $saved->save();

            abort(422, 'Cannot dispatch file sending job');
        }

        return response()->json([
            'ok' => true,
            'queued' => true,
            'message' => $saved,
        ], 202);
    }

    private function touchThreadLastMessage(ChannelThread $thread, ChannelMessage $saved): void
    {
        $lastAt = $saved->provider_date
            ? Carbon::createFromTimestamp((int)$saved->provider_date)
            : now();

        $thread->last_message_text = $saved->text ?? 'Вложение';
        $thread->last_message_at = $lastAt;
        $thread->last_message_external_id = $saved->external_message_id ? (int)$saved->external_message_id : null;
        $thread->save();
    }
}
