<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\NotificationSetting;
use App\Models\Task;
use App\Models\User;
use App\Notifications\CommentMentionInAppNotification;
use App\Notifications\CommentOnAssignedTaskEmailNotification;
use App\Notifications\CommentOnAssignedTaskInAppNotification;
use App\Notifications\CommentOnMyTaskEmailNotification;
use App\Notifications\CommentOnMyTaskInAppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class CommentController extends Controller
{
    /**
     * Получить комментарии к задаче
     */
    public function index(Task $task): JsonResponse
    {
         $comments = $task->comments()
            ->with('user')
            ->with([
                'user:id,name,email,avatar',
                'attachments:id,attachable_id,attachable_type,original_name,mime,size,disk,path,meta,created_at',
                'replyTo:id,task_id,user_id,content',
                'replyTo.user:id,name,email,avatar',
            ])
            ->orderBy('created_at', 'asc')
            ->get();
        return response()->json($comments);
    }

    /**
     * Добавить комментарий
     */
    public function store(Request $request, Task $task): JsonResponse
    {
        $data = $request->validate([
            'content' => 'nullable|string',
            'files'   => 'sometimes|array',
            'files.*' => 'file|max:10240',
            'mentioned_user_ids' => 'nullable|array',
            'mentioned_user_ids.*' => 'integer|exists:users,id',
            'reply_to_id'  => 'nullable|integer|exists:comments,id',
        ]);

        if (empty($data['content']) && !$request->hasFile('files')) {
            return response()->json([
                'message' => 'Content or file is required.',
            ], 422);
        }

        if (!empty($data['reply_to_id'])) {
            $belongs = Comment::where('id', $data['reply_to_id'])
                ->where('task_id', $task->id)
                ->exists();
            abort_unless($belongs, 422, 'reply_to_id does not belong to this task');
        }

        $comment = $task->comments()->create([
            'user_id' => Auth::id(),
            'content' => $data['content'],
            'reply_to_id' => $data['reply_to_id'] ?? null,
            'mentioned_user_ids' => $data['mentioned_user_ids'] ?? [],
        ]);

        // Сохраняем файлы
        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $file) {
                // Путь: comment_attachments/{task_id} (аналогично chat_attachments/{chat_id})
                $path = $file->store("comment_attachments/{$task->id}", 'public');

                // Создаём attachment через отношение (polymorphic)
                $comment->attachments()->create([
                    'uploaded_by'   => Auth::id(),
                    'disk'          => 'public',
                    'path'          => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime'          => $file->getClientMimeType(),
                    'size'          => $file->getSize(),
                    'sha256'        => hash_file('sha256', $file->getRealPath()),
                    'meta'          => null,
                ]);
            }
        }

        $authorId = Auth::id();

        // потенциальные получатели:
        // 1) создатель задачи (если не автор коммента)
        // 2) assignee задачи (если не автор коммента)
        // + потом упоминания (если включены) с приоритетом

        $recipientIds = [];

        // создатель задачи (предполагаю поле author_id; если у тебя другое — скажи)
        if (!empty($task->author_id) && (int)$task->author_id !== (int)$authorId) {
            $recipientIds[] = (int)$task->author_id;
        }

        // ответственный
        if (!empty($task->assignee_id) && (int)$task->assignee_id !== (int)$authorId) {
            $recipientIds[] = (int)$task->assignee_id;
        }

        $recipientIds = array_values(array_unique($recipientIds));

        // упомянутые (кроме автора)
        $mentionedIds = array_values(array_unique(array_map('intval', $data['mentioned_user_ids'] ?? [])));
        $mentionedIds = array_values(array_filter($mentionedIds, fn($id) => (int)$id !== (int)$authorId));

        // объединяем всех, кому вообще потенциально можем слать (создатель/ассайни + упоминания)
        $allIds = array_values(array_unique(array_merge($recipientIds, $mentionedIds)));

        if (!empty($allIds)) {
            $settingsByUser = NotificationSetting::query()
                ->whereIn('user_id', $allIds)
                ->get()
                ->keyBy('user_id');

            $users = User::query()
                ->whereIn('id', $allIds)
                ->get()
                ->keyBy('id');

            // 1) Упоминания — приоритет (если включено inapp_mentions)
            foreach ($mentionedIds as $uid) {
                $u = $users->get($uid);
                if (!$u) continue;

                $st = $settingsByUser->get($uid);
                if ((bool)($st?->inapp_mentions)) {
                    $u->notify(new CommentMentionInAppNotification($task, $comment));
                }
            }

            // 2) Комментарии к моим созданным задачам
            if (!empty($task->author_id) && (int)$task->author_id !== (int)$authorId) {
                $taskAuthorId = (int)$task->author_id;

                // если создатель уже упомянут — не шлём ему "комментарий", чтобы не было дубля
                if (!in_array($taskAuthorId, $mentionedIds, true)) {
                    $u = $users->get($taskAuthorId);
                    $st = $settingsByUser->get($taskAuthorId);

                    if ($u && (bool)($st?->inapp_comments_on_my_tasks)) {
                        $u->notify(new CommentOnMyTaskInAppNotification($task, $comment));
                    }
                    if ($u && (bool)($st?->email_comments_on_my_tasks)) {
                        $u->notify(new CommentOnMyTaskEmailNotification($task, $comment));
                    }
                }
            }

            // 3) Комментарии к задачам, где я ответственная
            if (!empty($task->assignee_id) && (int)$task->assignee_id !== (int)$authorId) {
                $assigneeId = (int)$task->assignee_id;

                // если ассайни уже упомянут — не шлём ему "комментарий", чтобы не было дубля
                if (!in_array($assigneeId, $mentionedIds, true)) {
                    $u = $users->get($assigneeId);
                    $st = $settingsByUser->get($assigneeId);

                    if ($u && (bool)($st?->inapp_comments_on_assigned_tasks)) {
                        $u->notify(new CommentOnAssignedTaskInAppNotification($task, $comment));
                    }
                    if ($u && (bool)($st?->email_comments_on_assigned_tasks)) {
                        $u->notify(new CommentOnAssignedTaskEmailNotification($task, $comment));
                    }
                }
            }
        }

        return response()->json($comment->load(['user', 'attachments', 'replyTo', 'replyTo.user']), 201);
    }

    /**
     * Удалить комментарий
     */
    public function destroy(Comment $comment): JsonResponse
    {
        if ($comment->user_id !== Auth::id()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Удаляем физические файлы + записи вложений
        $comment->load('attachments:id,attachable_id,disk,path');
        foreach ($comment->attachments as $att) {
            if ($att->disk && $att->path) {
                try { Storage::disk($att->disk)->delete($att->path); } catch (\Throwable $e) {}
            }
            $att->delete();
        }

        $comment->delete();
        return response()->json(['message' => 'Comment deleted']);
    }
}
