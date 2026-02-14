<?php

namespace App\Jobs;

use App\Models\ChannelMessage;
use App\Models\ChannelThread;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendTelegramFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;
    public int $tries = 2;

    public function __construct(
        public int $threadId,
        public int $messageId,
        public string $localPath,
        public string $originalName,
        public ?string $caption,
        public ?string $mime
    ) {}

    public function handle(): void
    {
        $thread = ChannelThread::with('channel.group')->findOrFail($this->threadId);
        $channel = $thread->channel;

        $msgModel = ChannelMessage::findOrFail($this->messageId);

        $token = (string) data_get($channel->secrets, 'telegram.bot_token', '');
        if ($token === '') {
            $this->failMessage($msgModel, 'Telegram bot token is not configured');
            return;
        }

        $chatId = (string) $thread->external_chat_id;

        [$method, $attachField] = $this->tgMethodByMime((string)($this->mime ?? ''));

        $endpoint = "https://api.telegram.org/bot{$token}/{$method}";

        if (!is_file($this->localPath)) {
            $this->failMessage($msgModel, 'Local file not found: ' . $this->localPath);
            return;
        }

        $stream = fopen($this->localPath, 'rb');
        if ($stream === false) {
            $this->failMessage($msgModel, 'Cannot open local file stream');
            return;
        }

        $http = Http::connectTimeout(20)
            ->timeout(180)
            ->retry(2, 700)
            ->withOptions([
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                ],
            ]);

        $t0 = microtime(true);

        try {
            $payload = ['chat_id' => $chatId];
            if ($this->caption) $payload['caption'] = $this->caption;

            $resp = $http
                ->attach($attachField, $stream, $this->originalName)
                ->post($endpoint, $payload);

        } catch (ConnectionException $e) {
            Log::error("Telegram {$method} connection failed", [
                'thread_id' => $thread->id,
                'group_channel_id' => $channel->id,
                'endpoint' => $endpoint,
                'elapsed_ms' => (int) round((microtime(true) - $t0) * 1000),
                'message' => $e->getMessage(),
            ]);
            $this->failMessage($msgModel, "Telegram {$method} connection failed");
            return;
        } catch (\Throwable $e) {
            Log::error("Telegram {$method} unexpected error", [
                'thread_id' => $thread->id,
                'group_channel_id' => $channel->id,
                'endpoint' => $endpoint,
                'elapsed_ms' => (int) round((microtime(true) - $t0) * 1000),
                'message' => $e->getMessage(),
            ]);
            $this->failMessage($msgModel, "Telegram {$method} unexpected error");
            return;
        } finally {
            if (is_resource($stream)) fclose($stream);
            // можно чистить файл после попытки (успех/ошибка)
            @unlink($this->localPath);
        }

        if (!$resp->ok()) {
            Log::warning("Telegram {$method} failed http", [
                'thread_id' => $thread->id,
                'group_channel_id' => $channel->id,
                'status' => $resp->status(),
                'body' => $resp->body(),
            ]);
            $this->failMessage($msgModel, "Telegram {$method} failed");
            return;
        }

        $json = $resp->json();
        if (!(($json['ok'] ?? false) === true)) {
            Log::warning("Telegram {$method} ok=false", [
                'thread_id' => $thread->id,
                'group_channel_id' => $channel->id,
                'json' => $json,
            ]);
            $this->failMessage($msgModel, "Telegram {$method} ok=false");
            return;
        }

        $tgMsg = $json['result'] ?? [];

        // обновляем placeholder-сообщение на реальное
        $msgModel->external_message_id = isset($tgMsg['message_id']) ? (string)$tgMsg['message_id'] : null;
        $msgModel->payload = $tgMsg;
        $msgModel->text = $tgMsg['caption'] ?? $tgMsg['text'] ?? $this->caption ?? $msgModel->text;
        $msgModel->provider_date = isset($tgMsg['date']) ? (int)$tgMsg['date'] : $msgModel->provider_date;
        $msgModel->delivery_status = 'sent';
        $msgModel->delivery_error = null;
        $msgModel->save();

        // обновим last_message_* у thread
        $lastAt = $msgModel->provider_date
            ? now()->setTimestamp((int)$msgModel->provider_date)
            : now();

        $thread->last_message_text = $msgModel->text ?? 'Вложение';
        $thread->last_message_at = $lastAt;
        $thread->last_message_external_id = $msgModel->external_message_id ? (int)$msgModel->external_message_id : null;
        $thread->save();
    }

    private function failMessage(ChannelMessage $m, string $error): void
    {
        $m->delivery_status = 'failed';
        $m->delivery_error = $error;
        $m->save();
    }

    private function tgMethodByMime(string $mime): array
    {
        if (str_starts_with($mime, 'image/')) return ['sendPhoto', 'photo'];
        if (str_starts_with($mime, 'video/')) return ['sendVideo', 'video'];
        if (str_starts_with($mime, 'audio/')) return ['sendAudio', 'audio'];
        return ['sendDocument', 'document'];
    }
}
