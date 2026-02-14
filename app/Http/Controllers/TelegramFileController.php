<?php

namespace App\Http\Controllers;

use App\Models\ChannelThread;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramFileController extends Controller
{
    public function download(Request $request, ChannelThread $thread)
    {
        $channel = $thread->channel;
        $group = $channel->group;

        abort_unless(
            $group->users()->whereKey($request->user()->id)->exists(),
            403,
            'Forbidden'
        );

        abort_unless($channel->provider === 'telegram', 422, 'Thread is not telegram provider');
        abort_unless(in_array($channel->status, ['active', 'error'], true), 422, 'Channel is not available');

        $data = $request->validate([
            'file_id' => 'required|string|min:5|max:255',
            'download' => 'sometimes|nullable|in:1,true,yes',
        ]);

        $fileId = trim((string)$data['file_id']);
        $forceDownload = isset($data['download']) && $data['download'] !== null;

        $token = (string) data_get($channel->secrets, 'telegram.bot_token', '');
        abort_unless($token !== '', 500, 'Telegram bot token is not configured for this group channel');

        // getFile -> file_path
        try {
            $resp = Http::timeout(15)->get("https://api.telegram.org/bot{$token}/getFile", [
                'file_id' => $fileId,
            ]);
        } catch (ConnectionException $e) {
            Log::error('Telegram getFile connection failed', [
                'group_channel_id' => $channel->id,
                'thread_id' => $thread->id,
                'file_id' => $fileId,
                'message' => $e->getMessage(),
            ]);
            abort(422, 'Telegram getFile connection failed');
        } catch (\Throwable $e) {
            Log::error('Telegram getFile unexpected error', [
                'group_channel_id' => $channel->id,
                'thread_id' => $thread->id,
                'file_id' => $fileId,
                'message' => $e->getMessage(),
            ]);
            abort(422, 'Telegram getFile unexpected error');
        }

        // token умер -> помечаем error
        if ($resp->status() === 401) {
            $channel->status = 'error';
            $channel->save();

            abort(422, 'Telegram bot token unauthorized. Reconnect integration.');
        }

        abort_unless($resp->ok(), 422, 'Telegram getFile failed');

        $json = $resp->json();
        abort_unless(($json['ok'] ?? false) === true, 422, 'Telegram getFile ok=false');

        $result = $json['result'] ?? [];
        $filePath = (string)($result['file_path'] ?? '');

        abort_unless($filePath !== '', 404, 'Telegram file_path not found');

        $fileSize = isset($result['file_size']) ? (int)$result['file_size'] : null;

        // download file from Telegram file API
        $fileUrl = "https://api.telegram.org/file/bot{$token}/{$filePath}";

        // имя файла: берем basename(file_path)
        $filename = basename($filePath) ?: 'telegram-file';

        // Попытка угадать mime по расширению (минимально, без сторонних пакетов)
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mime = $this->guessMimeByExt($ext);

        // Используем sink/tmp файл, чтобы не держать всё в памяти
        $tmpPath = storage_path('app/tmp');
        if (!is_dir($tmpPath)) {
            @mkdir($tmpPath, 0775, true);
        }
        $localTmp = $tmpPath . '/' . uniqid('tg_', true) . '_' . $filename;

        try {
            $dl = Http::timeout(60)
                ->withOptions(['sink' => $localTmp])
                ->get($fileUrl);
        } catch (ConnectionException $e) {
            Log::error('Telegram file download connection failed', [
                'group_channel_id' => $channel->id,
                'thread_id' => $thread->id,
                'file_id' => $fileId,
                'file_path' => $filePath,
                'message' => $e->getMessage(),
            ]);
            @unlink($localTmp);
            abort(422, 'Telegram file download connection failed');
        } catch (\Throwable $e) {
            Log::error('Telegram file download unexpected error', [
                'group_channel_id' => $channel->id,
                'thread_id' => $thread->id,
                'file_id' => $fileId,
                'file_path' => $filePath,
                'message' => $e->getMessage(),
            ]);
            @unlink($localTmp);
            abort(422, 'Telegram file download unexpected error');
        }

        if (!$dl->ok()) {
            Log::warning('Telegram file download failed', [
                'group_channel_id' => $channel->id,
                'thread_id' => $thread->id,
                'file_id' => $fileId,
                'file_path' => $filePath,
                'http_status' => $dl->status(),
            ]);
            @unlink($localTmp);
            abort(422, 'Telegram file download failed');
        }

        // Content-Disposition
        $dispositionType = $forceDownload ? 'attachment' : 'inline';
        $disposition = $dispositionType . '; filename="' . addslashes($filename) . '"';

        // stream response + удаление tmp файла после отдачи
        return response()->streamDownload(function () use ($localTmp) {
            $fh = fopen($localTmp, 'rb');
            if ($fh === false) return;

            while (!feof($fh)) {
                echo fread($fh, 1024 * 1024); // 1MB chunks
            }
            fclose($fh);

            @unlink($localTmp);
        }, $filename, array_filter([
            'Content-Type' => $mime,
            'Content-Disposition' => $disposition,
            'Content-Length' => $fileSize,
            'Cache-Control' => 'private, max-age=0, no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]));
    }

    private function guessMimeByExt(string $ext): string
    {
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            'json' => 'application/json',
            'zip' => 'application/zip',
            'rar' => 'application/vnd.rar',
            '7z' => 'application/x-7z-compressed',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            default => 'application/octet-stream',
        };
    }
}
