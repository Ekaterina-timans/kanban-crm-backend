<?php

namespace App\Console\Commands;

use App\Models\ChannelMessage;
use App\Models\ChannelThread;
use App\Models\GroupChannel;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramPollUpdates extends Command
{
    protected $signature = 'telegram:poll {--limit=50} {--timeout=0}';
    protected $description = 'Poll Telegram updates via getUpdates and upsert channel threads';

    public function handle()
    {
        $token = (string) config('services.telegram.bot_token');
        if ($token === '') {
            $this->error('TELEGRAM_BOT_TOKEN is not configured');
            return self::FAILURE;
        }

        $channels = GroupChannel::query()
            ->where('provider', 'telegram')
            ->whereIn('status', ['active', 'error'])
            ->get();

        if ($channels->isEmpty()) {
            $this->warn('No telegram channels found (active/error)');
            return self::SUCCESS;
        }

        foreach ($channels as $channel) {
            $tg = $channel->settings['telegram'] ?? [];
            $offset = (int)($tg['last_update_id'] ?? 0);
            $offset = $offset > 0 ? $offset + 1 : null;

            $params = [
                'limit' => (int)$this->option('limit'),
            ];

            $timeout = (int)$this->option('timeout');
            if ($timeout > 0) {
                $params['timeout'] = $timeout;
            }
            if ($offset !== null) {
                $params['offset'] = $offset;
            }

            // --- ловим сетевые ошибки / таймауты ---
            try {
                $resp = Http::timeout(max(20, $timeout + 10))
                    ->get("https://api.telegram.org/bot{$token}/getUpdates", $params);
            } catch (ConnectionException $e) {
                Log::error('Telegram getUpdates connection failed', [
                    'group_channel_id' => $channel->id,
                    'provider' => 'telegram',
                    'message' => $e->getMessage(),
                ]);

                $channel->status = 'error';
                $channel->save();

                $this->error("channel #{$channel->id}: connection failed (set status=error)");
                continue;
            } catch (\Throwable $e) {
                Log::error('Telegram getUpdates unexpected error', [
                    'group_channel_id' => $channel->id,
                    'provider' => 'telegram',
                    'message' => $e->getMessage(),
                ]);

                $channel->status = 'error';
                $channel->save();

                $this->error("channel #{$channel->id}: unexpected error (set status=error)");
                continue;
            }

            if (!$resp->ok()) {
                Log::error('Telegram getUpdates http failed', [
                    'group_channel_id' => $channel->id,
                    'provider' => 'telegram',
                    'http_status' => $resp->status(),
                    'body' => $resp->body(),
                ]);

                $channel->status = 'error';
                $channel->save();

                $this->error("channel #{$channel->id}: getUpdates http={$resp->status()} (set status=error)");
                continue;
            }

            $body = $resp->json();

            if (($body['ok'] ?? false) !== true) {
                Log::error('Telegram getUpdates ok=false', [
                    'group_channel_id' => $channel->id,
                    'provider' => 'telegram',
                    'body' => $body,
                ]);

                $channel->status = 'error';
                $channel->save();

                $this->error("channel #{$channel->id}: ok=false (set status=error)");
                continue;
            }

            if ($channel->status !== 'active') {
                $channel->status = 'active';
                $channel->save();
            }

            $updates = $body['result'] ?? [];
            if (!is_array($updates) || count($updates) === 0) {
                $this->line("channel #{$channel->id}: no updates");
                continue;
            }

            $maxUpdateId = (int)($tg['last_update_id'] ?? 0);

            foreach ($updates as $upd) {
                $updateId = (int)($upd['update_id'] ?? 0);
                if ($updateId > $maxUpdateId) $maxUpdateId = $updateId;

                $msg = $upd['message'] ?? $upd['edited_message'] ?? null;
                if (!$msg) continue;

                $chat = $msg['chat'] ?? null;
                if (!$chat) continue;

                $chatId = (string)($chat['id'] ?? '');
                if ($chatId === '') continue;

                $from = $msg['from'] ?? null;

                $thread = ChannelThread::updateOrCreate(
                    [
                        'group_channel_id' => $channel->id,
                        'external_chat_id' => $chatId,
                    ],
                    [
                        'external_peer_id' => $from ? (string)($from['id'] ?? null) : null,
                        'thread_type' => $chat['type'] ?? null,
                        'title' => $chat['title'] ?? null,
                        'username' => $chat['username'] ?? ($from['username'] ?? null),
                        'first_name' => $from['first_name'] ?? null,
                        'last_name' => $from['last_name'] ?? null,
                        'last_update_id' => $updateId,
                        'meta' => [
                            'chat' => $chat,
                            'from' => $from,
                        ],
                    ]
                );

                $msgId = $msg['message_id'] ?? null;

                ChannelMessage::updateOrCreate(
                    [
                        'channel_thread_id' => $thread->id,
                        'direction' => 'in',
                        'external_message_id' => $msgId !== null ? (string)$msgId : null,
                    ],
                    [
                        'external_update_id' => $updateId,
                        'sender_external_id' => $from ? (string)($from['id'] ?? null) : null,
                        'text' => $msg['text'] ?? null,
                        'payload' => $msg,
                        'provider_date' => isset($msg['date']) ? (int)$msg['date'] : null,
                    ]
                );
            }

            $settings = $channel->settings ?? [];
            $settings['telegram'] = array_merge($settings['telegram'] ?? [], [
                'last_update_id' => $maxUpdateId,
            ]);
            $channel->settings = $settings;
            $channel->save();

            $this->info("channel #{$channel->id}: processed " . count($updates) . " updates, last_update_id={$maxUpdateId}");
        }

        return self::SUCCESS;
    }
}
