<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\Task;
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
